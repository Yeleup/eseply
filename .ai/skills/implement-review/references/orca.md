# Orca adapter

Roles: **implementer — Claude**, **reviewer — Codex**. Coordinator id: `orca:$ORCA_TERMINAL_HANDLE`.

You are an Orca orchestration coordinator. First load Orca's own rules and follow them: `orca skills get orchestration --full`. Every command below takes `--json`; read ids from the receipts, never guess them.

## Run

```bash
orca status --json
orca orchestration run-create --objective "Issue #<N>: <title>" --json
```

## Implementer (SKILL step 3)

```bash
orca orchestration worker-start --spec "<implementer task>" --task-title "Implement #<N>" \
  --worktree new-top-level --name agent-issue-<N> --agent claude --setup run --timeout-ms 300000 --json
```

- From the receipt keep: task id, dispatch id `D1`, worktree id `W` (`effects[kind=worktree].id`), agent terminal handle `H1` (`effects[kind=terminal,role=agent].id`). Worktree path: `orca worktree show --worktree id:<W> --json`.
- `new-top-level` creates a fresh worktree from `origin/master` and runs `orca.yaml` setup (`make worktree-setup`) before the agent starts. Never place a worker with `--worktree current`: that is the coordinator's own checkout.
- Non-zero exit: read `failedStage` / `residualResources` and follow `orca skills get orchestration --reference references/recovery-and-cleanup.md`; retry with `--retry-of <dispatch> --task <task id>` and the same explicit placement. At most 3 attempts per task, then block.

## Waiting

```bash
orca orchestration check --wait --types "worker_done,escalation,question" --timeout-ms 540000 --json
orca orchestration reply --id <message_id> --body "<answer>" --json   # questions you can answer from the issue/docs
orca orchestration check --ack <delivery_id> --json
```

- A timeout or empty result is a checkpoint, not a failure: wait again. Keep `--timeout-ms` ≤ 540000 (a shell call is limited to 10 minutes).
- Validate each `worker_done` against the dispatch you expect and its `--outcome`.
- A worker question you cannot answer from the issue or docs → block (SKILL step 9).
- After an implementer or reviewer finishes, keep its terminal for the next round: `orca orchestration worker-retain --dispatch <id> --json`.
- Never type into a worker terminal (it becomes `user_takeover` and leaves Orca's control).

## Reviewer (SKILL step 4)

```bash
orca orchestration worker-start --spec "<read-only review task>" --task-title "Review #<N> r<k>" \
  --worktree id:<W> --agent codex --json
```

Keep dispatch `D2` and terminal `H2`. Codex runs with its sandbox disabled, so read-only is only a request: check `git -C <path> status --porcelain` and `HEAD` after every review.

## Follow-ups to the same agent (fixes, rebase, re-review)

```bash
orca orchestration worker-start --spec "<findings / rebase instructions>" --task-title "Fix #<N> r<k>" \
  --terminal <H1> --worktree id:<W> --json
orca orchestration worker-start --spec "<re-review task>" --task-title "Review #<N> r<k+1>" \
  --terminal <H2> --worktree id:<W> --json
```

`--terminal` reuses the agent with its conversation; `--worktree` must name `W` (`current` would mean the coordinator's checkout). `--model` / `--effort` cannot be combined with `--terminal`.

## Clean up (SKILL step 8, and step 9 when blocking)

```bash
orca orchestration worker-release --dispatch <latest implementer dispatch> --json
orca orchestration worker-release --dispatch <latest reviewer dispatch> --json
orca orchestration worker-list --terminal-state reclaimable --json   # must be empty
orca worktree rm --worktree id:<W> --run-hooks --json
```

`retained` rows of earlier dispatches whose terminals were reused are expected. `--run-hooks` runs the `orca.yaml` archive hook (containers, volumes, databases and database user of the worktree); without it they stay until `make worktree-prune CONFIRM=yes` in the main checkout.

## Scheduler

An Orca automation starts a fresh coordinator in the main checkout every 10 minutes when the queue has work:

```bash
orca automations create --name "esepteu: очередь агентов" --trigger "*/10 * * * *" --provider claude \
  --workspace path:<main checkout> --workspace-mode existing --fresh-session --enabled \
  --precheck "bash .ai/skills/implement-review/queue.sh has-work" \
  --prompt "Ты координатор очереди агентов. Выполни навык implement-review (адаптер Orca) для следующего issue из очереди."
```

Inspect with `orca automations list --json` and `orca automations runs --id <id> --json`, run once now with `orca automations run <id>`, disable with `orca automations edit <id> --disabled`.
