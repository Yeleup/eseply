#!/usr/bin/env bash
# Git worktree support for this Docker setup (Orca, Claude Code, `git worktree add`).
#
# A worktree gets its own .env (compose project, ports, databases, database user) and a real copy of
# vendor/. Short-lived commands (make test/artisan/composer/shell) run in one-off containers that mount
# the worktree and use the main checkout's MariaDB with the worktree's own databases and user;
# `make up` in a worktree starts a fully isolated stack instead.
#
#   setup            write .env, copy vendor/, composer install, create databases, migrate
#   check-env        fail unless .env was generated for this worktree (used by make)
#   ensure-db [db]   create this worktree's databases and database user (used by make test)
#   archive          best-effort teardown before the worktree is deleted; always exits 0
#   prune [--apply]  list (or remove) Docker resources and databases of deleted worktrees
#
# Requires bash, git >= 2.31, docker compose, flock, ss, realpath, cksum, od.
set -euo pipefail

ENV_FILE="${ENV_FILE:-.env}"
PORT_SLOTS=24

log() { printf '[worktree] %s\n' "$*" >&2; }
die() {
    log "ERROR: $*"
    exit 1
}

# env_get KEY [FILE]: value of KEY with surrounding quotes removed.
env_get() {
    local key="$1" file="${2:-$ENV_FILE}" value
    [ -f "$file" ] || return 0
    value="$(awk -v key="$key" 'index($0, key "=") == 1 { print substr($0, length(key) + 2); exit }' "$file")"
    case "$value" in
        \"*\" | \'*\') value="${value:1:${#value}-2}" ;;
    esac
    printf '%s\n' "$value"
}

git_dir() { git rev-parse --path-format=absolute --git-dir; }
git_common_dir() { git rev-parse --path-format=absolute --git-common-dir; }
is_linked_worktree() { [ "$(git_dir)" != "$(git_common_dir)" ]; }
main_root() { dirname "$(git_common_dir)"; }

db_container() {
    docker ps -q --filter "label=com.docker.compose.project=$1" --filter 'label=com.docker.compose.service=db' \
        --filter 'label=com.docker.compose.oneoff=False' --filter status=running 2>/dev/null | head -n 1 || true
}

# root_sql CONTAINER [mariadb args...]: SQL from stdin as MariaDB root; the password never leaves the container.
root_sql() {
    local container="$1"
    shift
    timeout "${SQL_TIMEOUT:-60}" docker exec -i "$container" \
        sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot --batch --skip-column-names "$@"' sh "$@"
}

# Escapes the GRANT wildcards `_` and `%` in a database name.
like_escape() { printf '%s' "$1" | sed 's/[\\_%]/\\&/g'; }

slugify() {
    printf '%s' "$1" | LC_ALL=C tr '[:upper:]' '[:lower:]' | LC_ALL=C sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//' \
        | cut -c1-20 | sed -E 's/-+$//'
}

random_hex() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }

# Ports claimed by the .env of any checkout of this repository, plus every port listening now.
reserved_ports() {
    local dir key
    git worktree list --porcelain | sed -n 's/^worktree //p' | while IFS= read -r dir; do
        for key in APP_PORT FORWARD_DB_PORT VITE_PORT; do
            env_get "$key" "$dir/$ENV_FILE"
        done
    done
    ss -Hltn 2>/dev/null | awk '{ sub(/.*:/, "", $4); print $4 }'
}

# allocate_ports SEED [BASE]: "app db vite" ports from the first free slot of a 100-port block
# (BASE defaults to a block in 20000-29900 derived from SEED, so each repository gets its own block).
allocate_ports() {
    local base="${2:-}" reserved start n slot port
    if [ -z "$base" ]; then
        base=$((20000 + $(printf '%s' "$1" | cksum | cut -d' ' -f1) % 100 * 100))
    fi
    reserved=" $(reserved_ports | sort -un | tr '\n' ' ') "
    start=$(($(printf '%s' "$PWD" | cksum | cut -d' ' -f1) % PORT_SLOTS))
    for ((n = 0; n < PORT_SLOTS; n++)); do
        slot=$(((start + n) % PORT_SLOTS))
        port=$((base + slot * 4))
        case "$reserved" in
            *" $port "* | *" $((port + 1)) "* | *" $((port + 2)) "*) continue ;;
        esac
        printf '%s %s %s\n' "$port" "$((port + 1))" "$((port + 2))"
        return 0
    done
    return 1
}

# render_env SRC KEY=VALUE...: SRC with the given keys replaced in place (missing keys appended).
render_env() {
    local src="$1"
    shift
    awk -v overrides="$(printf '%s\n' "$@")" '
        BEGIN {
            n = split(overrides, lines, "\n")
            for (i = 1; i <= n; i++) {
                if (lines[i] == "") continue
                eq = index(lines[i], "=")
                key = substr(lines[i], 1, eq - 1)
                order[++count] = key
                value[key] = substr(lines[i], eq + 1)
            }
        }
        {
            eq = index($0, "=")
            key = eq > 1 ? substr($0, 1, eq - 1) : ""
            if (key in value) {
                if (!(key in done)) {
                    print key "=" value[key]
                    done[key] = 1
                }
                next
            }
            print
        }
        END {
            header = 0
            for (i = 1; i <= count; i++) {
                key = order[i]
                if (key in done) continue
                if (!header) {
                    print ""
                    print "# Git worktree: generated by docker/worktree/worktree.sh - never copy this file into another checkout"
                    header = 1
                }
                print key "=" value[key]
            }
        }' "$src"
}

write_env() {
    local root="$1" src="$1/$ENV_FILE" main_env main_project main_db id slug db_slug db_prefix project dev_db
    local app_port db_port vite_port tmp
    [ -f "$src" ] || die "$src not found: set up the main checkout first (make init there)."
    main_env="$(env_get APP_ENV "$src")"
    case "$main_env" in
        local | development | testing) ;;
        *) die "Refusing to copy $src (APP_ENV=${main_env:-empty}) into a worktree." ;;
    esac
    [ "$(env_get WORKTREE_MANAGED "$src")" != 1 ] || die "$src is itself a worktree .env."
    main_project="$(env_get DOCKER_PROJECT_NAME "$src")"
    [[ "$main_project" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "Invalid DOCKER_PROJECT_NAME '$main_project' in $src."
    main_db="$(env_get DB_DATABASE "$src")"

    id="$(random_hex 4)"
    slug="$(slugify "$(basename "$PWD")")"
    project="${main_project}-wt-${slug:+$slug-}${id}"
    db_prefix="$(printf '%s' "${main_db:-app}" | LC_ALL=C tr -c 'A-Za-z0-9_' '_' | cut -c1-20)"
    db_slug="$(printf '%s' "${slug//-/_}" | cut -c1-16 | sed -E 's/_+$//')"
    dev_db="${db_prefix}_wt_${db_slug:+${db_slug}_}${id}"
    read -r app_port db_port vite_port < <(allocate_ports "$main_project" "$(env_get WORKTREE_PORT_BASE "$src")") \
        || die "No free port slot; set WORKTREE_PORT_BASE in $src."

    tmp="$(git rev-parse --path-format=absolute --git-path worktree-env.tmp)"
    render_env "$src" \
        "WORKTREE_MANAGED=1" \
        "WORKTREE_ID=$id" \
        "WORKTREE_GITDIR=$(git_dir)" \
        "DOCKER_PROJECT_NAME=$project" \
        "DOCKER_INFRA_PROJECT=$main_project" \
        "APP_PORT=$app_port" \
        "APP_URL=http://localhost:$app_port" \
        "FORWARD_DB_PORT=$db_port" \
        "VITE_PORT=$vite_port" \
        "DB_DATABASE=$dev_db" \
        "DB_USERNAME=wt_$id" \
        "DB_PASSWORD=$(random_hex 16)" \
        "MARIADB_TEST_DATABASE=${dev_db}_test" \
        "SESSION_COOKIE=${project//-/_}_session" >"$tmp"
    chmod 600 "$tmp"
    mv "$tmp" "$ENV_FILE"
    log "Wrote $ENV_FILE: project=$project url=http://localhost:$app_port database=$dev_db"
}

# Loads and validates a worktree .env generated by write_env for THIS worktree (dies otherwise).
load_worktree_env() {
    [ -f "$ENV_FILE" ] || die "No $ENV_FILE in this worktree: run make worktree-setup."
    [ "$(env_get WORKTREE_MANAGED)" = 1 ] \
        || die "$ENV_FILE was not generated for this git worktree (a copy of the main .env would take over the main stack). Delete it and run make worktree-setup."
    is_linked_worktree \
        || die "$ENV_FILE was generated for a git worktree, but this is the main checkout. Restore its own .env (make init)."
    [ "$(env_get WORKTREE_GITDIR)" = "$(git_dir)" ] \
        || die "$ENV_FILE was generated for another worktree ($(env_get WORKTREE_GITDIR)). Delete it and run make worktree-setup."

    WT_ID="$(env_get WORKTREE_ID)"
    WT_PROJECT="$(env_get DOCKER_PROJECT_NAME)"
    WT_INFRA="$(env_get DOCKER_INFRA_PROJECT)"
    WT_DB="$(env_get DB_DATABASE)"
    WT_TEST_DB="$(env_get MARIADB_TEST_DATABASE)"
    WT_USER="$(env_get DB_USERNAME)"
    [[ "$WT_ID" =~ ^[0-9a-f]{8}$ ]] || die "Invalid WORKTREE_ID in $ENV_FILE."
    [[ "$WT_INFRA" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "Invalid DOCKER_INFRA_PROJECT in $ENV_FILE."
    [[ "$WT_PROJECT" =~ ^${WT_INFRA}-wt-([a-z0-9-]+-)?${WT_ID}$ ]] || die "DOCKER_PROJECT_NAME in $ENV_FILE is not this worktree's project."
    [[ "$WT_DB" =~ ^[A-Za-z0-9_]+_wt_([a-z0-9_]+_)?${WT_ID}$ ]] || die "DB_DATABASE in $ENV_FILE is not this worktree's database."
    [ "$WT_TEST_DB" = "${WT_DB}_test" ] || die "MARIADB_TEST_DATABASE in $ENV_FILE must be ${WT_DB}_test."
    [ "$WT_USER" = "wt_$WT_ID" ] || die "DB_USERNAME in $ENV_FILE must be wt_$WT_ID."
}

copy_vendor() {
    local root="$1" tmp="vendor.tmp.$$"
    [ ! -L vendor ] || die "vendor/ is a symlink (Orca shared directories?): containers only see this worktree. Delete it and re-run."
    if [ -e vendor ] || [ ! -f "$root/vendor/autoload.php" ]; then
        return 0
    fi
    log "Copying vendor/ from the main checkout (a real copy: symlinks dangle in containers, hardlinks write through to main)."
    rm -rf "$tmp"
    cp -a --reflink=auto "$root/vendor" "$tmp"
    mv "$tmp" vendor
}

cmd_setup() {
    local root
    is_linked_worktree || die "Not a linked git worktree. In the main checkout use make init."
    root="$(main_root)"
    [ "$(realpath "$root")" != "$(pwd -P)" ] || die "Refusing to run in the main checkout."

    # Serialises port allocation between worktrees that are set up at the same time.
    exec 9>"$(git_common_dir)/worktree-setup.lock"
    flock -w 120 9 || die "Another worktree setup holds $(git_common_dir)/worktree-setup.lock."
    if [ -f "$ENV_FILE" ]; then
        load_worktree_env
        log "Keeping existing $ENV_FILE ($WT_PROJECT)."
    else
        write_env "$root"
        load_worktree_env
    fi
    exec 9>&-

    copy_vendor "$root"

    # Lets one-off containers and an opt-in `make up` start without a build; `make build` rebuilds it.
    if ! docker image inspect "$WT_PROJECT-app" >/dev/null 2>&1 && docker image inspect "$WT_INFRA-app" >/dev/null 2>&1; then
        docker tag "$WT_INFRA-app" "$WT_PROJECT-app"
    fi

    make --no-print-directory composer composer_args="install --no-interaction --prefer-dist --no-progress"
    grep -Eq '^APP_KEY=.+' "$ENV_FILE" || make --no-print-directory key-generate

    if make --no-print-directory -s ensure-db; then
        make --no-print-directory artisan artisan_args="migrate --force --no-interaction" \
            || log "WARNING: migrations failed; fix them and run: make artisan artisan_args=migrate"
    else
        log "WARNING: no running database, migrations skipped. Start the main stack (or make db-up here), then run: make artisan artisan_args=migrate"
    fi

    if [ ! -e .codex/config.toml ] && [ -f "$root/.codex/config.toml" ]; then
        mkdir -p .codex
        cp "$root/.codex/config.toml" .codex/config.toml
    fi

    log "Ready ($WT_PROJECT): make test | make artisan artisan_args=... | make up (own stack at $(env_get APP_URL)) | make down"
}

cmd_ensure_db() {
    local extra="${1:-}" project container main_db main_test db password
    load_worktree_env
    project="${RUN_PROJECT:-$WT_PROJECT}"
    [ "$project" = "$WT_PROJECT" ] || [ "$project" = "$WT_INFRA" ] || die "Unexpected compose project '$project'."
    container="$(db_container "$project")"
    [ -n "$container" ] \
        || die "No running database for compose project '$project': start the main stack (make up in the main checkout) or run make db-up here."
    if [ -n "$extra" ]; then
        [[ "$extra" =~ ^[A-Za-z0-9_]{1,64}$ ]] && [[ "$extra" == "${WT_DB}"_* ]] \
            || die "test_database must be a valid name starting with ${WT_DB}_."
    fi
    if [ "$project" = "$WT_INFRA" ]; then
        main_db="$(docker exec "$container" printenv MARIADB_DATABASE || true)"
        main_test="$(docker exec "$container" printenv MARIADB_TEST_DATABASE || true)"
        for db in "$WT_DB" "$WT_TEST_DB" $extra; do
            [ "$db" != "$main_db" ] && [ "$db" != "$main_test" ] || die "$ENV_FILE points at $project's own database '$db'."
        done
    fi
    password="$(env_get DB_PASSWORD)"
    [[ "$password" =~ ^[0-9a-f]{32}$ ]] || die "DB_PASSWORD in $ENV_FILE was not generated by make worktree-setup."

    {
        for db in "$WT_DB" "$WT_TEST_DB" $extra; do
            printf 'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$db"
        done
        printf "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\n" "$WT_USER" "$password"
        printf "ALTER USER '%s'@'%%' IDENTIFIED BY '%s';\n" "$WT_USER" "$password"
        # <db>_% also covers the test database, `make test test_database=...` and Laravel parallel-test databases.
        printf "GRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%%';\n" "$(like_escape "$WT_DB")" "$WT_USER"
        printf "GRANT ALL PRIVILEGES ON \`%s%%\`.* TO '%s'@'%%';\n" "$(like_escape "${WT_DB}_")" "$WT_USER"
    } | root_sql "$container"
}

# remove_project PROJECT DIR: containers, volumes, networks and image tag of a compose project started from DIR.
remove_project() {
    local project="$1" here="$2" dir
    while IFS= read -r dir; do
        if [ -n "$dir" ] && [ "$(realpath -m "$dir")" != "$here" ]; then
            log "Compose project $project has containers from $dir: not removing it."
            return 0
        fi
    done < <(docker ps -a --filter "label=com.docker.compose.project=$project" \
        --format '{{.Label "com.docker.compose.project.working_dir"}}' | sort -u)
    docker ps -aq --filter "label=com.docker.compose.project=$project" | xargs -r docker rm -f >/dev/null
    docker volume ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker volume rm >/dev/null
    docker network ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker network rm >/dev/null
    docker image rm "$project-app" >/dev/null 2>&1
    return 0
}

# remove_oneoffs PROJECT DIR: one-off containers (make test, Boost MCP, ...) started from DIR in PROJECT.
remove_oneoffs() {
    local project="$1" here="$2" id dir
    docker ps -a --filter "label=com.docker.compose.project=$project" --filter 'label=com.docker.compose.oneoff=True' \
        --format '{{.ID}} {{.Label "com.docker.compose.project.working_dir"}}' | while read -r id dir; do
        if [ "$(realpath -m "$dir")" = "$here" ]; then
            docker rm -f "$id" >/dev/null
        fi
    done
    return 0
}

drop_worktree_databases() {
    local container="$1" main_db main_test db
    main_db="$(docker exec "$container" printenv MARIADB_DATABASE)"
    main_test="$(docker exec "$container" printenv MARIADB_TEST_DATABASE)"
    {
        printf 'SET SESSION lock_wait_timeout = 10;\n'
        printf 'SHOW DATABASES;\n' | root_sql "$container" | while IFS= read -r db; do
            [ "$db" = "$WT_DB" ] || [[ "$db" == "${WT_DB}"_* ]] || continue
            [ "$db" != "$main_db" ] && [ "$db" != "$main_test" ] || continue
            printf 'DROP DATABASE IF EXISTS `%s`;\n' "$db"
        done
        printf "DROP USER IF EXISTS '%s'@'%%';\n" "$WT_USER"
    } | root_sql "$container" --force
}

cmd_archive() {
    local here container
    set +e
    [ -f "$ENV_FILE" ] || {
        log "No $ENV_FILE: nothing to clean up."
        return 0
    }
    if ! (load_worktree_env) 2>/dev/null; then
        log "$ENV_FILE was not generated for this worktree: skipping cleanup."
        return 0
    fi
    load_worktree_env
    here="$(pwd -P)"

    # Root-owned output of the vite container would block deleting the worktree.
    if [ -n "$(find public/build public/hot -maxdepth 0 -user 0 2>/dev/null)" ]; then
        timeout 30 docker run --rm -v "$here/public:/public" node:22-alpine rm -rf /public/build /public/hot
    fi

    remove_project "$WT_PROJECT" "$here"
    remove_oneoffs "$WT_INFRA" "$here"

    container="$(db_container "$WT_INFRA")"
    if [ -n "$container" ]; then
        drop_worktree_databases "$container"
    else
        log "Databases ${WT_DB}* and user $WT_USER not dropped ($WT_INFRA database is not running): run make worktree-prune in the main checkout later."
    fi
    log "Cleaned up $WT_PROJECT."
    return 0
}

cmd_prune() {
    local apply="${1:-}" root main_project main_db db_prefix live_ids dir id project container db user token live protected key
    root="$(main_root)"
    main_project="$(env_get DOCKER_PROJECT_NAME "$root/$ENV_FILE")"
    main_db="$(env_get DB_DATABASE "$root/$ENV_FILE")"
    [[ "$main_project" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "DOCKER_PROJECT_NAME is missing or invalid in $root/$ENV_FILE."
    db_prefix="$(printf '%s' "${main_db:-app}" | LC_ALL=C tr -c 'A-Za-z0-9_' '_' | cut -c1-20)"

    # Setup writes a worktree's .env under this lock before creating any of its resources, so holding it
    # for the whole prune keeps a worktree that is being set up from looking stale.
    exec 9>"$(git_common_dir)/worktree-setup.lock"
    flock -w 120 9 || die "A worktree setup holds $(git_common_dir)/worktree-setup.lock."

    live_ids=" "
    while IFS= read -r dir; do
        id="$(env_get WORKTREE_ID "$dir/$ENV_FILE")"
        [ -z "$id" ] || live_ids+="$id "
    done < <(git worktree list --porcelain | sed -n 's/^worktree //p')

    {
        docker ps -a --format '{{.Label "com.docker.compose.project"}}'
        docker volume ls --format '{{.Label "com.docker.compose.project"}}'
        docker network ls --format '{{.Label "com.docker.compose.project"}}'
        docker image ls --format '{{.Repository}}' | sed -n 's/-app$//p'
    } | sort -u | while IFS= read -r project; do
        [[ "$project" =~ ^${main_project}-wt-([a-z0-9-]+-)?([0-9a-f]{8})$ ]] || continue
        [[ "$live_ids" != *" ${BASH_REMATCH[2]} "* ]] || continue
        log "stale compose project: $project"
        [ "$apply" = --apply ] || continue
        docker ps -aq --filter "label=com.docker.compose.project=$project" | xargs -r docker rm -f >/dev/null
        docker volume ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker volume rm >/dev/null
        docker network ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker network rm >/dev/null
        docker image rm "$project-app" >/dev/null 2>&1 || true
    done

    docker ps -a --filter "label=com.docker.compose.project=$main_project" --filter 'label=com.docker.compose.oneoff=True' \
        --format '{{.ID}} {{.Label "com.docker.compose.project.working_dir"}}' | while read -r id dir; do
        [ ! -d "$dir" ] || continue
        log "stale one-off container $id (from deleted $dir)"
        [ "$apply" != --apply ] || docker rm -f "$id" >/dev/null
    done

    container="$(db_container "$main_project")"
    if [ -z "$container" ]; then
        log "$main_project database is not running: databases and users not checked."
    else
        # The main checkout's own databases and user are never stale, whatever their names look like.
        protected=" $main_db $(env_get MARIADB_TEST_DATABASE "$root/$ENV_FILE") $(env_get DB_USERNAME "$root/$ENV_FILE")"
        for key in MARIADB_DATABASE MARIADB_TEST_DATABASE MARIADB_USER; do
            protected+=" $(docker exec "$container" printenv "$key" || true)"
        done
        protected+=" "

        printf 'SHOW DATABASES;\n' | root_sql "$container" | while IFS= read -r db; do
            # Only names that worktree-setup generates: <prefix>_wt_[<slug>_]<id>[_<suffix>].
            [[ "$db" =~ ^${db_prefix}_wt_([a-z0-9_]+_)?[0-9a-f]{8}(_[A-Za-z0-9_]+)?$ ]] || continue
            [[ "$protected" != *" $db "* ]] || continue
            live=0
            for token in ${db//_/ }; do
                [[ "$live_ids" != *" $token "* ]] || live=1
            done
            [ "$live" = 0 ] || continue
            log "stale database: $db"
            [ "$apply" != --apply ] || printf 'DROP DATABASE IF EXISTS `%s`;\n' "$db" | root_sql "$container"
        done
        printf "SELECT user FROM mysql.user WHERE user REGEXP '^wt_[0-9a-f]{8}\$';\n" | root_sql "$container" | while IFS= read -r user; do
            [[ "$live_ids" != *" ${user#wt_} "* ]] || continue
            [[ "$protected" != *" $user "* ]] || continue
            log "stale database user: $user"
            [ "$apply" != --apply ] || printf "DROP USER IF EXISTS '%s'@'%%';\n" "$user" | root_sql "$container"
        done
    fi
    [ "$apply" = --apply ] || log "Dry run. Remove with: make worktree-prune CONFIRM=yes"
}

cd "$(git rev-parse --show-toplevel)"
case "${1:-}" in
    setup) cmd_setup ;;
    check-env) load_worktree_env ;;
    ensure-db) cmd_ensure_db "${2:-}" ;;
    archive) cmd_archive || true ;;
    prune) cmd_prune "${2:-}" ;;
    *) die "usage: $0 setup|check-env|ensure-db [database]|archive|prune [--apply]" ;;
esac
