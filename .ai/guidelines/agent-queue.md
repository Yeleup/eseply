# Agent Task Queue

Code changes are done through a queue of GitHub issues (label `agent`) worked by coordinators that follow the `implement-review` skill: one agent implements, another reviews, the PR is merged after a clean review.

This applies only to **entry sessions**: a session in the main checkout, or the AO orchestrator session. If you are a worker or a reviewer in a task worktree, ignore this section and do the task you were given.

When the user asks an entry session for a code change (feature, bug fix, refactoring, migration, UI change):

- Put it in the queue: write the user's request, with the context from the conversation, to a file and run `bash "$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/.ai/skills/implement-review/queue.sh" enqueue "<short Russian title>" <file>` (the main checkout's copy of the script). Reply with the issue link; a coordinator picks it up on its own.
- If the user says «сразу» (now): enqueue it, then claim that issue yourself (`queue.sh claim <owner> <number>`) and coordinate it per the `implement-review` skill.
- If the user says «сделай сам» or «без очереди»: do the change yourself in this session, without the queue.

Questions, analysis, code review, SQL for production data fixes and other work that does not change repository files are answered directly, without the queue.

## Reviewing a queue task

If you review a pull request or branch named `agent/issue-<N>`, whatever tool started you:

- do not modify, commit or push anything;
- review `git diff origin/master...HEAD` against issue #<N> (`gh issue view <N>`; only the issue author's comments count), the relevant `docs/` and these project rules;
- run the full `make test`;
- report every finding with severity, `file:line`, the failure scenario and a suggested fix, and end with a verdict: approve or request changes.
