#!/bin/sh
#
# The lock of the production stack and make deploy-status (docs/deployment.md). The Makefile runs it
# from the directory of docker-compose.yml:
#   make deploy                     deploy.sh deploy: the lock, git pull, then make deploy-steps
#   make build, up, down,           deploy.sh lock -- docker compose ..., with APP_ENV=production
#     down-volumes
#   make restart                    deploy.sh lock -- make down up, with APP_ENV=production
#   make deploy-steps               deploy.sh held: refuses unless make deploy runs it
#   make deploy-status              deploy.sh status
#
# One lock for every command that changes the production stack: they run one at a time. A command
# takes the lock before it does anything, make deploy before git pull, and holds it to its last
# step; a command that finds the lock taken refuses at once, without waiting, and names the one that
# holds it and since when. status does not take it.
#
# The lock is flock(1) on .deploy/lock through descriptor 9. This script hands the descriptor to the
# command it runs, and every process of that command inherits it: the lock is free once all of them
# have ended, and none of them changes the stack without it. Ctrl+C, a closed terminal or a reboot
# ends them all; a kill of the command alone (kill -9 <pid>) leaves what it started (git, make,
# docker compose) holding the lock until those end. The line in the file names the command that took
# the lock last and never keeps the lock by itself: a refusal and status name it only while its
# process still holds the lock, and otherwise list the holders: the processes on whose descriptor
# /proc/<pid>/fdinfo shows the lock. status learns whether the lock is held from the kernel, without
# taking it: a holder it sees, or the lock in /proc/locks; when neither settles it, it says unknown.
#
# A command under the lock gets descriptor 9 and DEPLOY_LOCK_HELD=1, and lock and held inside it use
# that lock instead of taking it again: make restart runs make down and make up, make deploy runs
# make deploy-steps of the pulled Makefile. Keep that contract when changing the lock: the version
# after a git pull runs under the lock that the version before the pull took.
#
# Settings, from the environment:
#   DEPLOY_COMMAND        how the lock names this command to the ones it refuses (make <target>)
#   DEPLOY_FETCH_TIMEOUT  seconds for status to fetch the branch that git pull brings (20)
#
# set -e does not act inside a function called from if, !, || or &&: such functions check every
# step themselves.
set -eu

command_name="${DEPLOY_COMMAND:-$0 $*}"
fetch_timeout="${DEPLOY_FETCH_TIMEOUT:-20}"

lock_dir=.deploy
lock_file="$lock_dir/lock"
status_ref=refs/deploy-status/upstream

fail() {
    printf 'error: %s\n' "$*" >&2
    exit 1
}

# This process holds the lock through descriptor 9, handed down by the command that took it.
holds_lock() {
    [ "${DEPLOY_LOCK_HELD:-}" = 1 ] &&
        [ -f "$lock_file" ] &&
        [ "/proc/$$/fd/9" -ef "$lock_file" ] &&
        flock -n 9 2> /dev/null
}

# Takes the lock for the rest of this command, or refuses at once. Under the lock of the command that
# runs this one, it keeps that lock.
take_lock() {
    if [ "${DEPLOY_LOCK_HELD:-}" = 1 ]; then
        holds_lock || fail "DEPLOY_LOCK_HELD=1, but descriptor 9 holds no lock on $lock_file. Nothing was done"
        return 0
    fi

    command -v flock > /dev/null || fail 'flock is not installed (util-linux); without it the production stack has no lock. Nothing was done'
    { mkdir -p "$lock_dir" && touch "$lock_file" && [ -f "$lock_file" ] && [ -w "$lock_file" ]; } 2> /dev/null ||
        fail "cannot create $lock_file. Nothing was done"
    exec 9>> "$lock_file"

    flock -n 9 || refuse_busy
    printf '%s (pid %s) since %s\n' "$command_name" "$$" "$(date '+%Y-%m-%d %H:%M:%S %z')" > "$lock_file" ||
        printf 'warning: cannot write the name of this command to %s; the lock is held all the same\n' "$lock_file" >&2
    export DEPLOY_LOCK_HELD=1
}

# The processes that hold the lock, one pid per line: the kernel shows the lock on a descriptor of
# theirs (a lock: line in /proc/<pid>/fdinfo), whether it was opened for reading or writing. A process
# that only has the file open (cat, less, a command that has been refused) holds nothing. Processes
# that do not show their descriptors to this user (another user's, a setuid program) are not listed.
lock_holders() {
    previous=''

    for descriptor in /proc/[0-9]*/fd/*; do
        [ "$descriptor" -ef "$lock_file" ] || continue
        pid="${descriptor#/proc/}"
        pid="${pid%%/*}"

        if [ "$pid" != "$previous" ] && grep -q '^lock:.* FLOCK ' "/proc/$pid/fdinfo/${descriptor##*/}" 2> /dev/null; then
            echo "$pid"
            previous="$pid"
        fi
    done
}

# Whether /proc/locks lists a lock on the file: 0 if it does, 1 if it does not, 2 if that cannot be
# read. The kernel prints the device of the file as major:minor in hex and its inode in decimal.
kernel_lists_lock() {
    file_id="$(stat -c '%d %i' "$lock_file" 2> /dev/null)" && [ -r /proc/locks ] || return 2
    device="${file_id% *}"
    key="$(printf '%02x:%02x:%s' $(((device >> 8) & 0xfff)) $(((device & 0xff) | ((device >> 12) & 0xfff00))) "${file_id#* }")"
    awk -v key="$key" '$2 == "FLOCK" && $6 == key { found = 1; exit } END { exit !found }' /proc/locks
}

# Whether a lock missing from /proc/locks and from the holders this user sees is free. In the initial
# pid namespace /proc/locks lists every lock. In another one (a container) it leaves out a lock whose
# taker has ended, as flock(1) ends after it takes the lock here: there only the holders show it, and
# it is free if every process of the namespace shows its descriptors (a zombie has none).
absence_means_free() {
    [ "$(readlink /proc/self/ns/pid 2> /dev/null)" = 'pid:[4026531836]' ] && return 0

    for process in /proc/[0-9]*; do
        [ -r "$process/fd" ] || process_has_ended "$process" || return 1
    done
}

# The process has ended: a zombie, or gone meanwhile.
process_has_ended() {
    case "$(sed -n 's/^.*) \([A-Za-z]\) .*$/\1/p' "$1/stat" 2> /dev/null)" in
        Z | X | '') return 0 ;;
        *) return 1 ;;
    esac
}

# Who holds the lock. recorded: the line of the file. holder_line: that line while its process holds
# the lock. Otherwise holder_pids: the processes that hold it, with their command lines; empty if none
# of them is visible. A command that has only just taken the lock has not written its line yet, a
# command killed on its own leaves the commands it started holding the lock, and flock(1) run by hand
# writes no line.
find_holders() {
    recorded="$(head -n 1 "$lock_file" 2> /dev/null || true)"
    recorded_pid="$(printf '%s\n' "$recorded" | sed -n 's/^.* (pid \([0-9][0-9]*\)) since .*$/\1/p')"
    holders="$(lock_holders)"
    holder_line=''
    holder_pids=''

    if [ -n "$recorded_pid" ] && printf '%s\n' "$holders" | grep -qx "$recorded_pid"; then
        holder_line="$recorded"
        return 0
    fi

    for holder in $holders; do
        holder_command="$(tr '\0' ' ' < "/proc/$holder/cmdline" 2> /dev/null | sed 's/ *$//')"
        holder_pids="${holder_pids:+$holder_pids, }pid $holder${holder_command:+ ($holder_command)}"
    done

    if [ -n "$holder_pids" ] && [ -n "$recorded" ]; then
        holder_pids="$holder_pids; $lock_file names $recorded, which no longer holds it: the holder is a command it started that still runs, or a command that has not written its own line"
    fi
}

# Another command holds the lock: refuses this one and names the holder.
refuse_busy() {
    find_holders

    if [ -n "$holder_line" ]; then
        fail "the production stack is busy: $holder_line. Nothing was done; run the command again when that one has finished"
    fi

    if [ -z "$holder_pids" ]; then
        fail "the production stack is busy: another command holds $lock_file, its processes are not visible to this user${recorded:+; the last command recorded there: $recorded}. Nothing was done; run the command again when that one has finished"
    fi

    fail "the production stack is busy: the lock is held by $holder_pids. Nothing was done; run the command again when the holder has finished"
}

# lock -- <command>: runs the command under the lock, or refuses at once.
cmd_lock() {
    if [ "${1:-}" = -- ]; then
        shift
    fi

    [ $# -gt 0 ] || fail 'usage: deploy.sh lock -- <command> [<argument>...]'
    take_lock
    exec "$@"
}

# make deploy: git pull and the steps under one lock. make read the Makefile before the pull, so the
# steps run in a new make, which reads the pulled one: make deploy-steps, under the same lock. git
# keeps the descriptor like every command here: a pull that outlives a killed deploy holds the lock to
# its end. So git must leave no helper running after it (an ssh master connection), or the helper
# would hold the lock as long.
cmd_deploy() {
    take_lock
    echo 'git pull'
    git pull || fail 'git pull failed; nothing was deployed'
    exec make --no-print-directory deploy-steps
}

# make deploy-steps: only inside make deploy, under its lock.
cmd_held() {
    holds_lock || fail "$command_name runs only inside make deploy, under its lock of the production stack: run make deploy"
}

# git fetch of the branch that git pull brings, into a ref of its own: not into the remote-tracking
# branch or FETCH_HEAD, which a git pull of a deploy that runs meanwhile writes and would find locked.
fetch_upstream() {
    set -- git -c gc.auto=0 -c maintenance.auto=false fetch --quiet --no-write-fetch-head --refmap= "$1" "+$2:$status_ref"

    if command -v timeout > /dev/null; then
        GIT_TERMINAL_PROMPT=0 timeout "$fetch_timeout" "$@"
    else
        GIT_TERMINAL_PROMPT=0 "$@"
    fi
}

# The commit of the checkout and how far it is behind the branch that git pull brings.
# GIT_OPTIONAL_LOCKS=0: git status does not write the index, which a git pull may hold meanwhile.
git_state() {
    GIT_OPTIONAL_LOCKS=0
    export GIT_OPTIONAL_LOCKS
    echo "commit: $(git log -1 --format='%h %cd %s' --date='format:%Y-%m-%d %H:%M %z' 2>&1 | head -n 1)"

    branch="$(git symbolic-ref --short -q HEAD 2> /dev/null || true)"
    remote=''
    merge=''

    if [ -n "$branch" ]; then
        remote="$(git config "branch.$branch.remote" 2> /dev/null || true)"
        merge="$(git config "branch.$branch.merge" 2> /dev/null || true)"
    fi

    if [ -z "$remote" ] || [ -z "$merge" ]; then
        echo "upstream: none for ${branch:-a detached HEAD}, git pull has nothing to bring"
    else
        fetch_status=0
        fetch_output="$(fetch_upstream "$remote" "$merge" 2>&1)" || fetch_status=$?

        case "$fetch_status" in
            0) reason='' ;;
            # timeout(1): 124 in coreutils, 143 (SIGTERM) in busybox.
            124 | 143) reason="no answer in ${fetch_timeout}s" ;;
            *) reason="$(printf '%s\n' "$fetch_output" | awk '/^(fatal|error): / { print; found = 1; exit } NF && first == "" { first = $0 } END { if (!found) print first }')" ;;
        esac

        if [ "$fetch_status" = 0 ]; then
            ref="$status_ref"
            checked='fetched just now'
        else
            ref='@{upstream}'
            checked="as of the last fetch; fetching it now failed: ${reason:-git fetch exited with $fetch_status}"
        fi

        behind="$(git rev-list --count "HEAD..$ref" 2> /dev/null || echo '?')"
        ahead="$(git rev-list --count "$ref..HEAD" 2> /dev/null || echo '?')"
        echo "upstream: $remote/${merge#refs/heads/}, commits behind: $behind, ahead: $ahead ($checked)"
    fi

    if ! changes="$(git status --porcelain --untracked-files=no 2>&1)"; then
        echo "local changes: unknown, $(printf '%s\n' "$changes" | head -n 1)"
    elif [ -z "$changes" ]; then
        echo 'local changes: none'
    else
        echo "local changes: $(printf '%s\n' "$changes" | wc -l | tr -d ' ') tracked files differ from the commit, git pull may refuse to run"
    fi
}

# Whether the lock is held and by whom, without taking it or opening the file. The processes of
# another user are not visible here: a lock that only they hold is held by an unnamed command, or
# unknown where /proc/locks does not list every lock.
lock_state() {
    if [ ! -f "$lock_file" ]; then
        echo 'lock: free'
        return 0
    fi

    find_holders

    if [ -n "$holder_line" ]; then
        echo "lock: held by $holder_line"
        return 0
    elif [ -n "$holder_pids" ]; then
        echo "lock: held by $holder_pids"
        return 0
    fi

    listed=0
    kernel_lists_lock || listed=$?

    if [ "$listed" = 0 ]; then
        echo "lock: held by a command whose processes are not visible to this user${recorded:+; the last command recorded in $lock_file: $recorded}"
    elif [ "$listed" = 2 ]; then
        echo "lock: unknown, no holder is visible to this user and /proc/locks cannot be read${recorded:+; last taken by $recorded}"
    elif absence_means_free; then
        echo "lock: free${recorded:+, last taken by $recorded}"
    else
        echo "lock: unknown, no holder is visible to this user, but some processes are not, and /proc/locks here does not list every lock${recorded:+; last taken by $recorded}"
    fi
}

# make deploy-status: read only, never takes the lock.
cmd_status() {
    git_state
    lock_state
}

case "${1:-}" in
    lock)
        shift
        cmd_lock "$@"
        ;;
    deploy) cmd_deploy ;;
    held) cmd_held ;;
    status) cmd_status ;;
    *) fail 'usage: deploy.sh lock -- <command> [<argument>...] | deploy | held | status' ;;
esac
