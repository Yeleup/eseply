# AO (Agent Orchestrator) adapter

Roles come from the AO project config and are the user's choice: **implementer — the project's worker agent**, **reviewer — the project's AO reviewer** (`autoReview`). Never pass `--harness` / `--model`, never change roles with `ao project set-config`. Check them with `ao project get <project> --json` (`config.worker`, `config.reviewers`, `config.autoReview`). Without a configured reviewer nothing may be merged: block with «ревьюер в AO не настроен».

Coordinator: the AO **orchestrator** session (`ao session get "$AO_SESSION_ID" --json` → `kind: orchestrator`). Coordinator id: `ao:$AO_SESSION_ID`. Project id: `$AO_PROJECT_ID`.

Unlike an Orca coordinator, the AO orchestrator is long-lived and drives **all** queue issues claimed by `ao:*` coordinators, one step at a time, from a loop.

## Loop

Start it once per orchestrator session (a `/loop` lives only in its session and expires after 7 days):

```
/loop 15m Очередь агентов: выполни один проход навыка implement-review (адаптер AO).
```

One pass:

1. **Adopt.** Issues labelled `agent:in-progress` whose latest `🤖 Взял в работу (ao:…)` comment names an AO session that is gone from `ao session ls -a -p "$AO_PROJECT_ID" --json` are yours: continue them.
2. **Claim.** `bash "$Q" claim "ao:$AO_SESSION_ID"` until it exits 3; spawn an implementer for each claimed issue.
3. **Advance** every issue you drive by one SKILL step (below), based on the issue thread, the PR and `ao review ls`.
4. **Heartbeat.** For an issue whose last comment is older than 2 hours, post `🤖 Всё ещё в работе: <stage>`.

## Implementer (SKILL step 3)

```bash
ao spawn --project "$AO_PROJECT_ID" --issue <N> --name issue-<N> --branch agent/issue-<N> --prompt "<implementer task>"
ao session ls -p "$AO_PROJECT_ID" --json     # find the worker session id (name issue-<N>)
```

AO creates the worktree and runs the project's `postCreate` (`make worktree-setup`) before the agent starts. AO has no command to read a worker's answer, so the implementer task must also say:

- report through the issue: when done, comment on #<N> starting with `🤖 Исполнитель:` — commit SHA, full `make test` result, what changed; open the PR itself (not a draft, body from the SKILL template, `Closes #<N>`) so AO links it and starts the review;
- ask through the issue: a question goes into a comment starting with `❓` and the worker stops and waits; answers arrive as messages from the orchestrator.

Answer questions with `ao send --session <worker> --message "<answer>"`, or block (SKILL step 9) if the issue and docs do not answer them.

## Review (SKILL step 4)

AO reviews the worker's PR with the configured reviewer:

```bash
ao review ls <worker> --json      # runs and verdicts: approved | changes_requested
ao review trigger <worker>        # no run started ~10 min after the PR / last push
```

- The `REVIEW` column of `ao session ls` shows GitHub state, not AO runs — use `ao review ls`.
- `REVIEW_OPERATION_FAILED` with «liveness probe inconclusive … /tmp/tmux-1000/default (No such file)» in the body: start an empty tmux server (`/usr/bin/tmux new-session -d -s ao-liveness-probe`) and trigger again.
- A run with no reviewer transcript after 60–90 s (`~/.claude/projects/-home-magzhan9292--ao-data-worktrees-<project>-<worker>/*.jsonl` for a Claude reviewer) did not receive its task: `ao review cancel <worker>` then `ao review trigger <worker>`.
- `ao review trigger` takes no task text. The reviewer gets the SKILL step 4 requirements from the project rules instead (CLAUDE.md / AGENTS.md, section «Agent Task Queue», which it reads in the worker's worktree).
- The reviewer works in the worker's worktree: after each run check `git -C <worktree> status --porcelain` and `HEAD` (SKILL step 4).

## Follow-ups (fixes, rebase)

```bash
ao send --session <worker> --message "<findings or rebase instructions; report in the issue as before>"
```

After the worker's `🤖 Исполнитель:` comment and push, the next review runs (trigger it if it does not start).

## Clean up (SKILL step 8, and step 9 when blocking)

`ao session kill` deletes the worktree **without** an archive hook, so archive first — and never kill a worker whose worktree you could not archive:

```bash
branch="$(ao session get <worker> -p "$AO_PROJECT_ID" --json | jq -r '.session.branch // .branch')"
W="$(git worktree list --porcelain | awk -v b="refs/heads/$branch" '/^worktree /{p=substr($0, 10)} $0 == "branch " b {print p}')"
[ -n "$W" ] && make -C "$W" worktree-archive && ao session kill <worker> -p "$AO_PROJECT_ID"
```

If `W` is empty or the archive fails, leave the session alive and say so in the issue comment.

Leftovers of a missed archive: `make worktree-prune` (list) and `make worktree-prune CONFIRM=yes` in the main checkout.
