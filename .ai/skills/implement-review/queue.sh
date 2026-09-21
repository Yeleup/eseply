#!/usr/bin/env bash
# GitHub Issues queue for the implement-review procedure (see SKILL.md). Shared by every orchestrator
# adapter (Orca, AO): all state lives in issue labels and comments, locks live in the shared git dir.
#
#   setup-labels                      create the queue labels if they are missing
#   enqueue <title> [body-file|-]     create an issue in the queue (label `agent`), print its URL
#   list                              show open queue issues with their state
#   has-work                          exit 0 if an issue can be claimed (scheduler precheck), 1 otherwise
#   claim <owner> [issue]             claim the oldest claimable issue (or the given one), print JSON;
#                                     exit 3 when the queue is empty
#   block <issue> <reason-file|->     hand the issue back to a human: label `agent:blocked` + comment
#   release <issue>                   drop `agent:in-progress` (after merge or when giving up quietly)
#   merge-lock acquire <owner> [wait-seconds]   take (or refresh) the repository-wide merge lock;
#                                               exit 2 if someone else holds it after waiting (default 540 s)
#   merge-lock release <owner>                  release it
#
# Only open issues authored by the gh-authenticated user are queue items, and only that user's comments
# count as activity: the repository is public, so anyone else's issue or comment is ignored.
#
# Claims and the merge lock are serialised with flock in the git common dir: every coordinator must run
# on this machine against a checkout of this clone (the main checkout or one of its worktrees).
#
# Requires bash, git, gh (authenticated), flock.
set -euo pipefail

LABEL_QUEUED=agent
LABEL_ACTIVE=agent:in-progress
LABEL_BLOCKED=agent:blocked
STALE_HOURS="${AGENT_QUEUE_STALE_HOURS:-3}"
MERGE_LOCK_STALE_MINUTES="${AGENT_QUEUE_MERGE_LOCK_STALE_MINUTES:-60}"
# More open queue issues than this are not expected; beyond it oldest-first is not guaranteed.
ISSUE_LIMIT=1000

log() { printf '[agent-queue] %s\n' "$*" >&2; }
die() {
    log "ERROR: $*"
    exit 1
}

common_dir() { git rev-parse --path-format=absolute --git-common-dir; }

REPO=""
OWNER=""
init_github() {
    [ -n "$REPO" ] || REPO="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
    [ -n "$OWNER" ] || OWNER="$(gh api user --jq .login)"
}

branch_for() { printf 'agent/issue-%s\n' "$1"; }

require_issue_number() {
    [[ "${1:-}" =~ ^[0-9]+$ ]] || die "Issue number expected, got '${1:-}'."
}

# jq over one issue (needs number, createdAt, author, comments, labels): "<number>\t<state>" when it can be
# claimed — state `queued`, or `stale` for an in-progress issue without an author comment for STALE_HOURS.
state_jq() {
    printf '%s' '([.labels[].name]) as $names
        | (.author.login) as $author
        | ([.comments[]? | select(.author.login == $author) | .createdAt] + [.createdAt] | max | fromdateiso8601) as $active
        | select($names | index("@BLOCKED@") | not)
        | if ($names | index("@ACTIVE@")) then
              (if $active < (now - @STALE_SECONDS@) then "\(.number)\tstale" else empty end)
          else "\(.number)\tqueued" end' \
        | sed -e "s/@BLOCKED@/$LABEL_BLOCKED/" -e "s/@ACTIVE@/$LABEL_ACTIVE/" -e "s/@STALE_SECONDS@/$((STALE_HOURS * 3600))/"
}

# Claimable issues as "<number>\t<state>", oldest first (search index: may lag a few seconds).
claimable() {
    gh issue list -R "$REPO" --state open --label "$LABEL_QUEUED" --author "$OWNER" --limit "$ISSUE_LIMIT" \
        --json number,createdAt,author,comments,labels \
        --jq "sort_by(.createdAt) | .[] | $(state_jq)"
}

# "<number>\t<state>" if the issue is claimable right now, nothing otherwise. Reads the issue directly,
# not through the search index, which lags a few seconds behind label changes.
verify_claimable() {
    gh issue view "$1" -R "$REPO" --json number,state,createdAt,author,comments,labels \
        --jq "select(.state == \"OPEN\" and .author.login == \"$OWNER\")
            | select([.labels[].name] | index(\"$LABEL_QUEUED\"))
            | $(state_jq)"
}

cmd_setup_labels() {
    gh label create "$LABEL_QUEUED" -R "$REPO" --color 1d76db --description 'Очередь агентов: задача ждёт координатора' --force >/dev/null
    gh label create "$LABEL_ACTIVE" -R "$REPO" --color fbca04 --description 'Очередь агентов: задача в работе у координатора' --force >/dev/null
    gh label create "$LABEL_BLOCKED" -R "$REPO" --color d93f0b --description 'Очередь агентов: нужен человек (вопрос в комментарии)' --force >/dev/null
    log "Labels ready in $REPO: $LABEL_QUEUED, $LABEL_ACTIVE, $LABEL_BLOCKED."
}

cmd_enqueue() {
    local title="${1:-}" body="${2:-}" body_file
    [ -n "$title" ] || die "usage: queue.sh enqueue <title> [body-file|-]"
    body_file="$(mktemp)"
    trap 'rm -f "$body_file"' RETURN
    if [ "$body" = - ]; then
        cat >"$body_file"
    elif [ -n "$body" ]; then
        cp "$body" "$body_file"
    fi
    # Labels first, then exactly one create request: retrying a create could duplicate the issue.
    if [ -z "$(gh label list -R "$REPO" --search "$LABEL_QUEUED" --json name --jq ".[] | select(.name == \"$LABEL_QUEUED\") | .name")" ]; then
        cmd_setup_labels
    fi
    gh issue create -R "$REPO" --title "$title" --body-file "$body_file" --label "$LABEL_QUEUED"
}

cmd_list() {
    gh issue list -R "$REPO" --state open --author "$OWNER" --limit "$ISSUE_LIMIT" \
        --search "label:$LABEL_QUEUED,$LABEL_ACTIVE,$LABEL_BLOCKED" \
        --json number,title,labels,updatedAt \
        --jq '.[] | "#\(.number)\t\([.labels[].name | select(startswith("agent"))] | join(","))\t\(.updatedAt)\t\(.title)"'
}

cmd_has_work() {
    [ -n "$(claimable | head -n 1)" ]
}

cmd_claim() {
    local owner="${1:-}" wanted="${2:-}" line candidate number state branch resumed status
    [ -n "$owner" ] || die "usage: queue.sh claim <owner> [issue]"
    [ -z "$wanted" ] || require_issue_number "$wanted"

    # Serialises claims of every coordinator on this machine: pick + label happen under one lock.
    exec 8>"$(common_dir)/agent-queue.lock"
    flock -w 60 8 || die "Another coordinator holds $(common_dir)/agent-queue.lock."

    line=""
    if [ -n "$wanted" ]; then
        line="$(verify_claimable "$wanted")"
        [ -n "$line" ] || die "Issue #$wanted is not claimable (needs to be open, yours, labelled $LABEL_QUEUED, not blocked or already in progress)."
    else
        for candidate in $(claimable | cut -f1); do
            line="$(verify_claimable "$candidate")"
            [ -z "$line" ] || break
        done
        [ -n "$line" ] || {
            log "Queue is empty."
            exit 3
        }
    fi
    number="${line%%$'\t'*}"
    state="${line#*$'\t'}"
    branch="$(branch_for "$number")"

    # Exit code 2 means "no such branch"; any other failure must not be mistaken for a fresh start.
    status=0
    git ls-remote --exit-code --heads origin "$branch" >/dev/null 2>&1 || status=$?
    case "$status" in
        0) resumed=true ;;
        2) resumed=false ;;
        *) die "git ls-remote origin failed (exit $status): cannot tell whether $branch exists." ;;
    esac

    gh issue edit "$number" -R "$REPO" --add-label "$LABEL_ACTIVE" >/dev/null
    if [ "$state" = stale ]; then
        gh issue comment "$number" -R "$REPO" --body "🤖 Предыдущий координатор молчал больше ${STALE_HOURS} ч — задачу забрал $owner и продолжает$([ "$resumed" = true ] && echo " с ветки \`$branch\`")." >/dev/null
    elif [ "$resumed" = true ]; then
        gh issue comment "$number" -R "$REPO" --body "🤖 Взял в работу ($owner), продолжаю с существующей ветки \`$branch\`." >/dev/null
    else
        gh issue comment "$number" -R "$REPO" --body "🤖 Взял в работу ($owner). Ветка: \`$branch\`." >/dev/null
    fi
    exec 8>&-

    printf '{"number":%s,"url":"https://github.com/%s/issues/%s","branch":"%s","resumed":%s,"stale":%s}\n' \
        "$number" "$REPO" "$number" "$branch" "$resumed" "$([ "$state" = stale ] && echo true || echo false)"
}

cmd_block() {
    local number="${1:-}" reason="${2:-}"
    require_issue_number "$number"
    [ -n "$reason" ] || die "usage: queue.sh block <issue> <reason-file|->"
    gh issue comment "$number" -R "$REPO" --body-file "$reason" >/dev/null
    gh issue edit "$number" -R "$REPO" --add-label "$LABEL_BLOCKED" --remove-label "$LABEL_ACTIVE" >/dev/null
    log "Issue #$number blocked: answer in the issue and remove $LABEL_BLOCKED to put it back in the queue."
}

cmd_release() {
    local number="${1:-}"
    require_issue_number "$number"
    gh issue edit "$number" -R "$REPO" --remove-label "$LABEL_ACTIVE" >/dev/null
}

# The merge lock is a file naming its owner; every read-modify-write of it happens under a short flock,
# so two coordinators can never both take it or break each other's fresh lock. Its mtime is the lease:
# taking it again as the same owner renews it; a lease older than MERGE_LOCK_STALE_MINUTES may be broken.
merge_lock_try() {
    local owner="$1" lock holder
    lock="$(common_dir)/agent-merge.lock"
    (
        flock -w 30 7 || exit 1
        holder="$(cat "$lock" 2>/dev/null || true)"
        if [ -n "$holder" ] && [ "$holder" != "$owner" ]; then
            if [ -z "$(find "$lock" -maxdepth 0 -mmin +"$MERGE_LOCK_STALE_MINUTES" 2>/dev/null)" ]; then
                printf '%s\n' "$holder"
                exit 2
            fi
            log "Breaking merge lock of '$holder': not renewed for $MERGE_LOCK_STALE_MINUTES min."
        fi
        printf '%s\n' "$owner" >"$lock"
    ) 7>"$(common_dir)/agent-merge.mutex"
}

cmd_merge_lock() {
    local action="${1:-}" owner="${2:-}" wait="${3:-540}" lock holder status waited=0
    [ -n "$owner" ] || die "usage: queue.sh merge-lock acquire|release <owner> [wait-seconds]"
    lock="$(common_dir)/agent-merge.lock"
    case "$action" in
        acquire)
            [[ "$wait" =~ ^[0-9]+$ ]] || die "wait-seconds must be a number."
            while true; do
                status=0
                holder="$(merge_lock_try "$owner")" || status=$?
                case "$status" in
                    0)
                        log "Merge lock held by $owner (lease renewed)."
                        return 0
                        ;;
                    2) ;;
                    *) die "Could not take $(common_dir)/agent-merge.mutex." ;;
                esac
                if [ "$waited" -ge "$wait" ]; then
                    log "Merge lock is held by '$holder'; gave up after ${wait}s."
                    return 2
                fi
                sleep 5
                waited=$((waited + 5))
            done
            ;;
        release)
            (
                flock -w 30 7 || exit 1
                holder="$(cat "$lock" 2>/dev/null || true)"
                if [ -z "$holder" ]; then
                    exit 0
                fi
                [ "$holder" = "$owner" ] || die "Merge lock is held by '$holder', not '$owner'."
                rm -f "$lock"
                log "Merge lock released by $owner."
            ) 7>"$(common_dir)/agent-merge.mutex"
            ;;
        *) die "usage: queue.sh merge-lock acquire|release <owner> [wait-seconds]" ;;
    esac
}

command="${1:-}"
case "$command" in
    setup-labels | enqueue | list | has-work | claim | block | release) init_github ;;
esac
case "$command" in
    setup-labels) cmd_setup_labels ;;
    enqueue) cmd_enqueue "${2:-}" "${3:-}" ;;
    list) cmd_list ;;
    has-work) cmd_has_work ;;
    claim) cmd_claim "${2:-}" "${3:-}" ;;
    block) cmd_block "${2:-}" "${3:-}" ;;
    release) cmd_release "${2:-}" ;;
    merge-lock) cmd_merge_lock "${2:-}" "${3:-}" "${4:-}" ;;
    *) die "usage: queue.sh setup-labels|enqueue|list|has-work|claim|block|release|merge-lock (see the header)" ;;
esac
