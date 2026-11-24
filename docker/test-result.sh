#!/usr/bin/env bash
# Records one run of a project's full test command as a JSON file, so that the result of a run is written by the
# command itself rather than retold by whoever ran it: the agent queue merges a commit only with a full green run of it
# (agent-queue test-result, merge). The reference of agent-queue-kit: a project copies it (satu-plus keeps it as
# docker/test-result.sh, the config's tests.recorder) and calls it around its test steps, as docs/test-result.md shows:
#
#   start <run> <result-dir> [<js-file>...]   first test step of the run (<result-dir> absolute or ~/…): note HEAD,
#                                             whether the worktree is clean,
#                                             the time and the JavaScript test files the run gives node; a second
#                                             start of the same run keeps the first one's notes
#   step <run> <name> <command...>            run a test step: its stdout goes to the terminal and to
#                                             <run>/<name>.log, its exit code is the step's. <name> is js (node --test),
#                                             php (Pest) or any other [a-z][a-z0-9-]*; a step with another name runs
#                                             unrecorded (one warning line)
#   finish <run> <exit>                       last: write the result file, remove <run>
#
# <run> is the run's scratch directory (start creates it; the caller names it once per run). Environment:
#   TEST_RESULT_ARGS      the run's test_args
#   TEST_RESULT_FULL_ARGS the test_args of a full run (default "--compact"): a run with other ones is filtered
#   TEST_RESULT_STEPS     the steps a full run must run, separated by blanks or line breaks (unset: "php"); a value
#                         with no step (empty too) or with a name that cannot be a step makes every run not full
#   TEST_RESULT_JS_GLOB   the complete JavaScript collection, relative to the worktree root (default
#                         tests/JavaScript/*.test.mjs). It is not taken from the caller, whose file list an argument can
#                         narrow: start lists it in the worktree itself. A project without JavaScript tests matches nothing.
# The defaults are those of format version 2: a PHP and JavaScript project switches from a copy that wrote version 2
# without changing its test command.
#
# Recording is a side effect: whatever fails here, the tests run and exit as they would without it. start and step never
# fail the run, finish always exits 0 and prints at most one warning line on stderr. Only bash, git and coreutils.
#
# Storage: one file per run, <result-dir>/<SHA>/<UTC start time>-<random>.json, never overwritten: a later filtered or
# failed run at the same SHA leaves an earlier full green one in place. Format (version 3; agent-queue reads 2 as well):
#   sha, head_after           HEAD at the start and at the end (40 hex)
#   worktree                  absolute path of the checkout the tests ran in
#   test_args, filtered       the run's test_args; filtered: they are not TEST_RESULT_FULL_ARGS
#   clean_before, clean_after `git status --porcelain` (untracked files that are not ignored included) was empty
#   clean                     clean_before, clean_after and head_after = sha
#   js                        {files (given to node), all_files (JS_TEST_GLOB in the worktree), complete (the files
#                             given are exactly those)}
#   required                  TEST_RESULT_STEPS as a list (malformed: its words as given)
#   steps                     every step that ran, in the order of their first run: {name, exit, summary}. summary:
#                             js — "tests N, pass N, fail N" from node --test; php — Pest's "Tests: ..." line; another
#                             step — the last non-empty line of its stdout; null when not found
#   exit                      exit code of the run: the failed step's, 0 when every step passed
#   full                      the whole suite ran on a clean tree: not filtered, clean, at least one step ran,
#                             TEST_RESULT_STEPS names at least one step and only valid names, every required step ran, the JS collection is complete and, when it has files, the js step ran
#                             with a summary
#   green                     full, exit and the exit of every step 0, every step has a summary
#   started_at, finished_at   UTC, ISO 8601
# The caller's shell options (an exported SHELLOPTS brings them here) go back unchanged to the test command, which runs
# through caller_run; the recorder's own notes run with nounset and without errexit and pipefail, so that a failed note
# never fails or skips a run. Read from SHELLOPTS itself: a command substitution would not show errexit.
CALLER_OPTIONS=":$SHELLOPTS:"
set -u +e +o pipefail

# caller_run <command...>: the command with the caller's state of the options set above, run as bash runs it (a
# builtin or an exported function too); called last, so the options it sets never reach the recorder's notes.
caller_run() {
    local option
    for option in errexit pipefail nounset; do
        if [[ "$CALLER_OPTIONS" == *":$option:"* ]]; then set -o "$option"; else set +o "$option"; fi
    done
    "$@"
}

# The complete JavaScript collection, relative to the worktree root.
JS_TEST_GLOB="${TEST_RESULT_JS_GLOB:-tests/JavaScript/*.test.mjs}"
# A step name: also the name of its files in <run>.
STEP_NAME='^[a-z][a-z0-9-]*$'

warn() { printf 'test-result: %s\n' "$*" >&2; }

# Writes value $3 of run $1 to the note file $2; get reads it back (empty when missing).
put() { printf '%s' "$3" 2>/dev/null >"$1/$2"; }
get() { cat "$1/$2" 2>/dev/null; }

# true when the worktree has no change and no untracked file that is not ignored; false also when git fails.
clean_state() {
    local status
    if status="$(git status --porcelain --untracked-files=normal 2>/dev/null)" && [ -z "$status" ]; then
        echo true
    else
        echo false
    fi
}

# A JSON string of $1 on one line: control characters dropped, quotes and backslashes escaped.
json_string() {
    local s
    s="$(printf '%s' "$1" | LC_ALL=C tr -d '\000-\037')"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    printf '"%s"' "$s"
}

# json_string, or null for an empty value.
json_text() {
    if [ -n "$1" ]; then json_string "$1"; else printf null; fi
}

# $1 when it is an exit code, null otherwise.
json_code() {
    if [[ "$1" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf null; fi
}

# The text of a log without terminal control: with a TTY, docker prints CR LF line ends, a NUL and colour sequences.
plain_log() {
    LC_ALL=C tr -d '\000\r' <"$1" 2>/dev/null | LC_ALL=C sed "s/$(printf '\033')\[[0-9;?]*[ -\/]*[@-~]//g"
}

# Pest's last "Tests: 1 failed, 300 passed (900 assertions)" line, spaces collapsed.
php_summary() {
    plain_log "$1" | LC_ALL=C grep -aE '^[[:space:]]*Tests:[[:space:]]' | tail -n 1 \
        | LC_ALL=C sed -e 's/^[[:space:]]*//' -e 's/[[:space:]][[:space:]]*/ /g' -e 's/ $//'
}

# "tests N, pass N, fail N" from the totals of node --test: "# tests N" (TAP reporter, the output is not a terminal)
# or "ℹ tests N" (spec reporter, a terminal); empty when any of them is missing.
js_summary() {
    local totals tests pass fail
    totals="$(plain_log "$1" | LC_ALL=C grep -aE '^(# |ℹ )(tests|pass|fail) [0-9]+$')"
    tests="$(printf '%s\n' "$totals" | sed -n 's/^.* tests \([0-9]*\)$/\1/p' | tail -n 1)"
    pass="$(printf '%s\n' "$totals" | sed -n 's/^.* pass \([0-9]*\)$/\1/p' | tail -n 1)"
    fail="$(printf '%s\n' "$totals" | sed -n 's/^.* fail \([0-9]*\)$/\1/p' | tail -n 1)"
    if [ -n "$tests" ] && [ -n "$pass" ] && [ -n "$fail" ]; then
        printf 'tests %s, pass %s, fail %s' "$tests" "$pass" "$fail"
    fi
}

# The last non-empty line of a log, blanks collapsed, at most 200 characters: the summary of a step that is neither js
# nor php.
last_line() {
    local line
    line="$(plain_log "$1" | LC_ALL=C sed -e 's/^[[:space:]]*//' -e 's/[[:space:]][[:space:]]*/ /g' -e 's/ $//' \
        | LC_ALL=C grep -a . | tail -n 1)"
    printf '%s' "${line:0:200}"
}

# The summary of step $1 from its log $2.
step_summary() {
    case "$1" in
        js) js_summary "$2" ;;
        php) php_summary "$2" ;;
        *) last_line "$2" ;;
    esac
}

utc_now() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# Lines $@ sorted, without duplicates; nothing for no arguments.
sorted_lines() {
    [ "$#" -eq 0 ] || printf '%s\n' "$@" | LC_ALL=C sort -u
}

cmd_start() {
    local run="$1" dir="$2" sha
    local -a all
    shift 2
    [ ! -d "$run" ] || return 0
    # The result directory as the queue reads it: ~ is the home directory; a relative one would land in the checkout.
    # shellcheck disable=SC2088
    case "$dir" in
        '~') dir="$HOME" ;;
        '~/'*) dir="$HOME/${dir#\~/}" ;;
        /*) ;;
        *) return 0 ;;
    esac
    # A failure here only means no record: finish reports the missing directory.
    mkdir -m 700 "$run" 2>/dev/null || return 0
    sha="$(git rev-parse --verify -q HEAD 2>/dev/null)" || sha=""
    put "$run" dir "$dir"
    # The files this run gives node, and the whole collection as the glob lists it in the worktree.
    sorted_lines "$@" >"$run/js_files" 2>/dev/null
    mapfile -t all < <(shopt -s nullglob; for file in $JS_TEST_GLOB; do printf '%s\n' "$file"; done)
    sorted_lines "${all[@]}" >"$run/js_all_files" 2>/dev/null
    put "$run" sha "$sha"
    put "$run" clean_before "$(clean_state)"
    put "$run" started_at "$(utc_now)"
    put "$run" worktree "$(pwd -P)"
    return 0
}

cmd_step() {
    local run="$1" name="$2" status
    shift 2
    if [[ ! "$name" =~ $STEP_NAME ]]; then
        warn "step name «$name» is not [a-z][a-z0-9-]*: the step runs unrecorded."
        caller_run "$@"
        exit
    fi
    # Not recording (start could not create the directory, or the step cannot be listed): the step as it is; a step
    # that is not listed never counts.
    if [ ! -d "$run" ] \
        || { ! grep -qxF -- "$name" "$run/steps" 2>/dev/null && ! printf '%s\n' "$name" 2>/dev/null >>"$run/steps"; }; then
        caller_run "$@"
        exit
    fi
    # Only stdout is copied: the summaries are there, and stderr stays where it was.
    (caller_run "$@") | tee -- "$run/$name.log"
    status="${PIPESTATUS[0]}"
    put "$run" "$name.exit" "$status"
    return "$status"
}

cmd_finish() {
    local run="$1" status="$2" dir sha head_after clean_before clean_after clean js_files js_all_files name i
    local js_complete=false js_text="" filtered=true full_args=--compact all_passed=true all_summaries=true
    local full=false green=false started_at stamp args="${TEST_RESULT_ARGS-}" worktree target tmp json steps_json
    local required_json="" ran_required=true
    local -a names=() exits=() texts=() required=()
    [ -z "${TEST_RESULT_FULL_ARGS+set}" ] || full_args="$TEST_RESULT_FULL_ARGS"
    # Every word of every line; no word at all (an empty value or blanks only) leaves the list empty, and an empty list
    # is no requirement met. Only an unset TEST_RESULT_STEPS means the default.
    read -r -d '' -a required <<<"${TEST_RESULT_STEPS-php}"
    [ "${#required[@]}" -gt 0 ] || ran_required=false
    if [ ! -d "$run" ]; then
        warn "no result recorded: could not create $run."
        return 0
    fi
    dir="$(get "$run" dir)"
    sha="$(get "$run" sha)"
    clean_before="$(get "$run" clean_before)"
    js_files=""
    js_all_files=""
    if [ -f "$run/js_files" ] && [ -f "$run/js_all_files" ]; then
        js_files="$(grep -c '' "$run/js_files")"
        js_all_files="$(grep -c '' "$run/js_all_files")"
        ! cmp -s "$run/js_files" "$run/js_all_files" || js_complete=true
    fi
    started_at="$(get "$run" started_at)"
    worktree="$(get "$run" worktree)"
    if [ -f "$run/steps" ]; then
        while IFS= read -r name; do
            [[ "$name" =~ $STEP_NAME ]] || continue
            names+=("$name")
            exits+=("$(get "$run" "$name.exit")")
            texts+=("$(step_summary "$name" "$run/$name.log")")
            [ "$name" != js ] || js_text="${texts[-1]}"
        done <"$run/steps"
    fi
    rm -rf "$run"
    if [[ ! "$sha" =~ ^[0-9a-f]{40}$ ]] || [ -z "$dir" ] || [[ ! "$js_files" =~ ^[0-9]+$ ]] \
        || [[ ! "$js_all_files" =~ ^[0-9]+$ ]]; then
        warn "no result recorded: the start of the run was not noted in $run."
        return 0
    fi

    head_after="$(git rev-parse --verify -q HEAD 2>/dev/null)" || head_after=""
    clean_after="$(clean_state)"
    clean=false
    if [ "$clean_before" = true ] && [ "$clean_after" = true ] && [ "$head_after" = "$sha" ]; then clean=true; fi
    [ "$args" != "$full_args" ] || filtered=false
    steps_json=""
    for i in "${!names[@]}"; do
        [ "${exits[$i]}" = 0 ] || all_passed=false
        [ -n "${texts[$i]}" ] || all_summaries=false
        steps_json+="${steps_json:+,}$(printf '{"name":"%s","exit":%s,"summary":%s}' "${names[$i]}" \
            "$(json_code "${exits[$i]}")" "$(json_text "${texts[$i]}")")"
    done
    for name in "${required[@]}"; do
        required_json+="${required_json:+,}$(json_string "$name")"
        [[ "$name" =~ $STEP_NAME ]] && [[ " ${names[*]} " == *" $name "* ]] || ran_required=false
    done
    if [ "$filtered" = false ] && [ "$clean" = true ] && [ "${#names[@]}" -gt 0 ] && [ "$ran_required" = true ] \
        && [ "$js_complete" = true ] && { [ "$js_all_files" -eq 0 ] || [ -n "$js_text" ]; }; then
        full=true
    fi
    if [ "$full" = true ] && [ "$status" = 0 ] && [ "$all_passed" = true ] && [ "$all_summaries" = true ]; then
        green=true
    fi

    json="$(printf '{"version":3,"sha":"%s","head_after":%s,"worktree":%s,"test_args":%s,"filtered":%s,' \
        "$sha" "$(json_text "$head_after")" "$(json_text "$worktree")" "$(json_string "$args")" "$filtered")"
    json+="$(printf '"clean_before":%s,"clean_after":%s,"clean":%s,' "${clean_before:-false}" "$clean_after" "$clean")"
    json+="$(printf '"js":{"files":%s,"all_files":%s,"complete":%s},' "$js_files" "$js_all_files" "$js_complete")"
    json+="$(printf '"required":[%s],"steps":[%s],' "$required_json" "$steps_json")"
    json+="$(printf '"exit":%s,"full":%s,"green":%s,"started_at":%s,"finished_at":"%s"}' \
        "$(json_code "$status")" "$full" "$green" "$(json_text "$started_at")" "$(utc_now)")"

    # Temporary name first, then a rename in the same directory: a reader never sees half a file.
    target="$dir/$sha"
    stamp="$(printf '%s' "$started_at" | tr -d ':-')"
    if mkdir -p "$target" 2>/dev/null && tmp="$(mktemp "$target/.run.XXXXXXXX" 2>/dev/null)"; then
        if printf '%s\n' "$json" >"$tmp" 2>/dev/null && mv -f "$tmp" "$target/${stamp:-run}-${tmp##*.}.json" 2>/dev/null; then
            return 0
        fi
        rm -f "$tmp" 2>/dev/null
    fi
    warn "no result recorded: cannot write to $target."
    return 0
}

command="${1:-}"
[ "$#" -eq 0 ] || shift
case "$command" in
    start) cmd_start "${1:-}" "${2:-}" "${@:3}" ;;
    step)
        [ "$#" -ge 3 ] || { warn "usage: test-result.sh step <run> <name> <command...>"; exit 2; }
        cmd_step "$@"
        ;;
    finish) cmd_finish "${1:-}" "${2:-}" ;;
    *)
        warn "usage: test-result.sh start|step|finish (see the header)"
        exit 2
        ;;
esac
