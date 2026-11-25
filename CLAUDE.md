<laravel-boost-guidelines>
=== .ai/agent-queue rules ===

<!-- agent-queue rule v7 -->

# Agent Task Queue

Code changes are done through a queue of GitHub issues (label `agent`) worked by coordinators that follow the `agent-queue` skill: Claude implements, Codex reviews, the PR is merged after a clean review.

The queue rules below apply only to **entry sessions**: a session in the main checkout. A worker in a task worktree or an Orca coordinator ignores them and does the task it was given; a reviewer follows «Reviewing a queue task».

When the user asks an entry session for a code change (feature, bug fix, refactoring, migration, UI change):

- Put it in the queue: write the user's request, with the context from the conversation, to a file and run `agent-queue enqueue "<short Russian title>" <file>`. Reply with the issue link; a coordinator picks it up on its own.
- Settle every open interpretation with the user (AskUserQuestion) before enqueueing: a coordinator claims a new issue within minutes and reads the thread once, so «напишите — поправлю задачу» comes too late.
- In the issue body keep «Запрос пользователя» (quoted), «Решения пользователя» and your own proposals apart; ask about any proposal that changes existing behaviour, or leave it out.
- A task about the design is enqueued only after the user approved its mock-up: «Mock-up of a design task» below.
- The repository is public: the issue never contains data from the local database, the production servers or real documents (names, phone numbers, addresses, personal account numbers, meter readings, payments, ids of real organizations or subscribers, tokens or keys). Describe the case in general terms.
- Run `agent-queue list` first. If the change needs code from an open queue issue, add a line `Зависит от #N` (the queue holds the issue until #N is closed); if it only touches the same module, add «Связано с #N». After enqueueing, `agent-queue list` shows `waits:#N` for a registered dependency.
- Enqueueing replaces implementation in any effort or workflow mode.
- If the user says «сразу» (now): enqueue it (a task about the design once its mock-up is approved). Only in an Orca terminal (`ORCA_TERMINAL_HANDLE` is set), claim that issue yourself (`agent-queue claim "orca:$ORCA_TERMINAL_HANDLE" <number>`) and coordinate it per the `agent-queue` skill; anywhere else say that the Orca automation picks it up within a few minutes.
- If the user says «сделай сам» or «без очереди»: do the change yourself in this session, without the queue.
- The queue's settings (`.agent-queue/`) are changed by the owner only, never through the queue.
- The queue itself — the `agent-queue` command and skill, the watchdog, the test result format and the kit's reference recorder, this rule's template — is the code of agent-queue-kit. Outside the kit's own repository a request to change it is only enqueued, in the kit's queue: `agent-queue list --kit`, then `agent-queue enqueue --kit "<short Russian title>" <file>` (the rules for the issue body above apply; describe this project's case in general terms). It never goes into this project's queue and is never changed from this session, «сразу» and «сделай сам» included.

Questions, analysis, code review, SQL for production data fixes and other work that does not change repository files are answered directly, without the queue.

## Mock-up of a design task

A task about the design — the request changes what the user sees: a new screen or a new state of a screen, a layout, new elements — is enqueued only after the user approved its mock-up. No mock-up for a typo, a change with nothing visible (backend) or a bug whose fix makes the screen look as designed. The user may always say «без макета» (the task is enqueued without one) or «покажи макет» (a mock-up also where this criterion asks for none).

- Where: before `agent-queue enqueue` of such a task, «сразу» included. Not with «сделай сам» or «без очереди»: the session makes the change as before — «покажи макет» there gets the mock-up made, reviewed and shown as below up to «Утверждаю», and nothing is enqueued. Never for the user's remarks to an MVP (`agent-queue enqueue-plan --mvp`, «Acceptance of an MVP») and never in the start skill `agent-queue-start`: an MVP has its own design board.
- The mock-up: a temporary directory of its own for each mock-up, outside every checkout (`mktemp -d`), holding only the page `<dir>/index.html` (the inputs of its review go elsewhere). The page is self-contained — its CSS and images inside the file (inline CSS and SVG, `data:` URIs): Orca publishes that one file, never files beside it — and stays within the upload limit of the Orca CLI. It shows only the screens and states the request changes, each at the widths 390 and 1440 as on the board of the start skill; where it helps, the current look beside it («было → станет»), redrawn in markup and CSS after the project's current screens (its preview page or design board when there is one, otherwise its current markup), so that it looks like the product, not a sketch. Invented data only — the link is public: nothing from the databases, logs, coordinator caches or production servers of the projects, no screenshots of the running app.
- Review: Codex reviews it as «Codex review of a document for the user» says, with `agent-queue review-doc --pages <dir>` on that directory as it is — no `Artifact read` export: the file is local already. The user is shown the version the last review saw, unchanged, with the summary of the review.
- Showing: `O="${ORCA_CLI_COMMAND:-orca-ide}"`, never a bare `orca` (on Linux outside an Orca terminal that is GNOME's screen reader). The first version: `"$O" artifacts share <dir>/index.html --json`; Orca's answer gives the link (`result.shareUrl`), the artifact id (`result.artifact.slug`) and the link's expiry (`result.artifact.expiresAt`, when not null). A next version of the same mock-up: `"$O" artifacts update <dir>/index.html --json` — the same path keeps the link; an update moves the expiry, so take it from that answer. Give the user the link and its expiry, if any, then AskUserQuestion «Макет: утверждаю?» with «Утверждаю» and «Есть правки». Remarks: a new version, its review, a new showing.
- Publishing fails — `artifact_sharing_disabled` (publishing is off: only the user turns it on, Orca Settings → Artifacts), Orca does not answer, any other error of `artifacts`: tell the user the reason and enqueue nothing until the user says «без макета» or removes the cause.
- After «Утверждаю»: `agent-queue enqueue` with a section `## Утверждённый макет` in the issue body — `- Ссылка: <link>`, `- id: <id>`, `- Срок: <expiry>` (only when there is one) and the screens and states the mock-up shows. The approved publication never changes again, no `update` of it: the implementer and the reviewer see the very version the user approved; another version is another mock-up and another «Утверждаю».

- The kit never deletes the approved mock-up, merged or not: its link stays until the user deletes it in Orca's artifacts panel (the id is in the task) — Orca itself closes it at its expiry (`- Срок:`, 30 days after the last `share` or `update`). A coordinator that finds the approved mock-up gone or expired blocks the task (`agent:blocked`): show it again as above and post its new section `## Утверждённый макет` as a comment of that issue (`gh issue comment <N> --repo Yeleup/eseply --body-file <file>`; the newest section counts), or the user answers «без макета» there; then the label comes off.

## Start of an MVP

The start skill `agent-queue-start` takes a new project to its MVP: it asks the owner questions, writes the specification (ТЗ), the plan and the mock-up as files in `docs/mvp/`, and after the owner's «да» puts the plan into the queue. `/agent-queue-start` starts it in any project, whenever the owner runs it: the skill makes its own checks. It exists in Claude only.

- A new project — «без ТЗ и задач» — has no `docs/mvp/` in the main checkout and has never had a queue task: `gh issue list --repo Yeleup/eseply --label agent --state all --limit 1 --json number --jq length` prints `0` (no issue labelled `agent`, open or closed). An entry session checks it once, before its first answer; it runs `gh` only when `docs/mvp/` is missing.
- In a new project add one line to that answer offering the start skill, and start the skill only on the owner's «да». The line never replaces the answer to the owner's request.
- Only that `0` makes a project new. A failed check (`gh` exits non-zero: no network, no auth) is not «no tasks»: offer nothing; you may say that the check failed.
- A Codex session has no start skill: in a new project, instead of the offer, and whenever the owner asks for `/agent-queue-start` or the start of an MVP, it advises in one line to open the project in Claude for the start skill.

## Acceptance of an MVP

The autopilot of an MVP ends with the owner's acceptance. Once the Codex acceptance passed, its driver posts «🤖 MVP готов к вашей приёмке» with the stand to look at in the open «MVP» issue #E (label `agent:mvp`: `gh issue list --repo Yeleup/eseply --label agent:mvp --state open`) and blocks #E until the owner decides.

- The owner says «принимаю» (or otherwise clearly accepts the MVP): run `agent-queue autopilot accept <E>` — it removes `agent:autopilot` and closes #E; the project then lives on with the ordinary queue. Exit 4: tell the owner why (its stderr — no «готов» for the current tasks, a task still open) and change nothing.
- The owner's remarks to the ready MVP, or after «🤖 Приёмка MVP не прошла за 2 раунда»: they are tasks of the same MVP, never issues of `agent-queue enqueue`, and get no mock-up («Mock-up of a design task» is not for an MVP). Settle them with the owner as above, write them to a plan file with `tasks` only in a temporary directory (`agent-queue enqueue-plan --help`: new keys, `title`, `what`, `acceptance` checked by tests, `stage`; the rules for the issue body above hold for the texts), run `agent-queue enqueue-plan --mvp <E> <file>`, then `agent-queue autopilot resume <E>`: it removes `agent:blocked`, the autopilot goes on, and once the new tasks are closed the Codex acceptance runs again, then the owner's. Reply with the issue links. Exit 4 of `enqueue-plan` naming the limit `total`: ask the owner to raise `total` in the block «Пределы автопилота» of #E, then run it again.

## Codex review of a document for the user

Before an entry session shows the user a document or page for a decision — a Claude Docs document, an artifact page, a Claude Design canvas, a file with a plan, specification, mock-up or diagram — Codex reviews it, synchronously in this session, not through an issue, a PR or the queue. A short plan of a few points in the chat answer goes without review.

- Run `agent-queue review-doc` (`agent-queue review-doc --help`); never assemble `codex exec` yourself. The reviewer is always a separate Codex run with a clean context, also when a Codex session wrote the document.
- Its inputs go to a temporary directory, never into the repository: a Claude Docs document exported to markdown and its diagrams to images as they render, and the `--request` file with the user's request and decisions quoted verbatim. Codex gets these, the document and the images — never your own assessment of the document.
- An artifact page or a Claude Design canvas Codex views and clicks through itself, in Orca's built-in browser: save the files of the whole artifact — not only the changed ones: a screen missing from them does not load — to a temporary directory (`Artifact read` with `paths`, every file of its `files` listing, and `out_dir`) and pass that directory as `--pages <dir>`, with the document that goes with it, if any. No screenshots by hand; Claude Docs diagrams stay images. On a next round `--changed <file>` names the changed screens too.
- Give the call a tool timeout of 10 minutes (Claude Code: Bash `timeout: 600000`); the command itself stops Codex after 9 minutes. When that is not enough, run it in the background with a larger `--timeout <seconds>` and wait for it to end.
- Exit 4 (`VERDICT: changes`): fix the findings and review again. A review of a document already reviewed and then edited names the changed sections and diagrams (`--changed <file>`): Codex checks only them, the rest is context. The next round also passes the previous report and your answer to each finding, fixed or why you disagree (`--previous <report> --answers <file>`). At most 3 rounds, then Fable's answer (next point) and at most 3 more; Codex reviews every round the same way.
- After the third `VERDICT: changes` ask the strongest Claude model with effort `max`, once, about the remaining findings. Currently that is Fable: in Claude Code a new sub-agent (Agent tool with `model: fable` and `effort: max`), never a fork of this session; a name without a version means the latest Fable model. It gets the `--request` file, the document and the other inputs Codex got (the markdown export, the images, the `--pages` directory), every Codex report and your answers to the findings — never your own assessment of the document. For every finding it answers «fix it — and how» or «do not fix — and why»; it changes and publishes nothing.
- This session runs rounds 4–6 like rounds 1–3: first per Fable's answer, then on its own. When Codex repeats a finding Fable answered, your answer to it (`--answers`) gives Fable's reasoning itself: Codex sees only the files `agent-queue review-doc` passes. Fable is not asked again, not even about new findings of rounds 4–6.
- A Codex session cannot ask Fable: after round 3 the user decides, as below. The same when Fable cannot be asked or its run fails, with the note «Fable не отвечал: <причина>» in the summary.
- With the document the user gets a short summary: in which round Codex approved, what was fixed, whether Fable was asked and what it decided. Not approved after round 6 (round 3 without Fable): what was fixed, which findings remain and why you disagree, and Fable's position on the findings it answered; the user decides. Codex unavailable or its run failed (exit 1, the reason on stderr): the document goes with the note «Codex не проверял: <причина>», never silently without review.

## Reviewing a queue task

If you review a pull request or branch named `agent/issue-<N>`, whatever tool started you:

- do not modify, commit or push anything, and do not spawn sub-agents;
- in the task worktree (`HEAD` is that branch): review `git diff origin/master...HEAD` against issue #<N> (`gh issue view <N> --repo Yeleup/eseply`; only the issue author's comments count), the relevant documentation and these project rules, and run the full `make test`;
- anywhere else (for example the main checkout): review `gh pr diff <pr> --repo Yeleup/eseply` the same way; tests here would test other code, so report «тесты не запускались» unless you ran `make test` in a worktree checked out at that branch;
- report every finding with severity, `file:line`, the failure scenario and a suggested fix, then the reviewed SHA and the `make test` summary, and end with the line `VERDICT: approve` or `VERDICT: changes`.

=== .ai/laravel-docker-template rules ===

# Laravel Docker Template

The `laravel-docker-template` directory is the reusable source template for this project's Docker setup.

When changing Docker, runtime, or local development configuration in the project root, make the equivalent generic change in `laravel-docker-template` during the same task.

This applies to:

- `.dockerignore`
- `.env.docker.example`
- `Dockerfile`
- `Makefile`
- `docker-compose.yml`
- `docker-compose.override.yml`
- `docker/app/*`
- `docker/worktree/*`
- `orca.yaml`
- Docker-related README instructions

Keep the template reusable. Do not copy project-specific secrets, local machine paths, app names, generated files, or one-off values into `laravel-docker-template` unless the change is intentionally part of the reusable template.

=== .ai/prod-server rules ===

# Боевой сервер

- Алиас `esepteu-prod`, пользователь `developer`, проект в `~/eseply`.
- Деплой: `server-kit job esepteu deploy -- 'cd ~/eseply && make deploy'`, затем `server-kit job-wait esepteu <задание>`. Если на сервере `make deploy-status` отвечает «No rule to make target», там ещё Makefile без блокировки: этот первый деплой — `'cd ~/eseply && git pull && make deploy'` и только когда другая команда стека не идёт (`docs/deployment.md`, «Первый деплой с блокировкой»). Отката нет: команды отката не существует. Состояние (только чтение): `ssh esepteu-prod 'cd ~/eseply && make deploy-status'`.
- Нагрузка: `server-status esepteu`. Ошибки приложения: `server-kit logs esepteu --errors`.
- Стек — один Docker Compose проект из `docker-compose.yml` (Octane на FrankenPHP, очередь, планировщик, MariaDB, Redis): `make deploy` пересобирает и пересоздаёт его на месте, приложение при этом коротко недоступно. Команды, которые меняют стек, идут по одной: если занято, команда сразу отказывает и называет, что уже идёт, — не повторять её в цикле и не останавливать ту команду без просьбы владельца (`docs/deployment.md`, «Одна команда за раз»). `make import` и `make import-media` в production не отказывают и заменяют боевую базу и файлы — только по просьбе владельца.
- Сервер общий: рядом работает one-market (свой compose-проект, порт 8500), там же лежат остановленные копии sala и satu-plus — команды esepteu их не трогают, а перезапуск Docker и перезагрузка сервера задевают и one-market. Compose-проект esepteu на сервере называется `eseply` (контейнеры `eseply-app-1` и т. д.). Сайт открывают по домену через веб-сервер на 80/443; порт приложения esepteu — 8600, опубликован только на `127.0.0.1` (`APP_PORT=127.0.0.1:8600` в `.env` сервера), веб-сервер ходит на него; порт приложения one-market 8500 файрвол закрывает по IPv6, по IPv4 Docker публикует его мимо файрвола (`docs/deployment.md`, «Доступ к серверу»).
- Переезд: `docs/deployment.md`, раздел «Перенос на другой сервер».
- Доступ — по общему правилу «Боевые серверы» и инструкции server-kit (`server-kit help`).

=== .ai/project-documentation rules ===

# Project Documentation Guideline

This project uses documentation as the source of truth for business logic.

Before implementing a feature, check:

- `docs/technical-specification.md`
- `docs/business-rules.md`
- relevant files in `docs/modules/*.md`

When changing business logic, update the relevant documentation in the same task.

If a module documentation file does not exist yet, create it before implementing the feature.

Business logic changes include:

- changed calculations;
- changed validation rules;
- changed statuses;
- changed entity relationships;
- changed user roles or permissions;
- changed receipt, payment, accrual, tariff, normative, meter, or service logic.

Update `docs/changelog.md` when business rules or module behavior change.

A task is not complete until related docs are updated.

=== .ai/project-testing rules ===

# Project Testing

This project uses Makefile commands for all test execution.

## Required test command

Always run tests using:

```bash
make test
```

Filter or pass other arguments with `test_args`:

```bash
make test test_args="--compact --filter=SomeTest"
```

`make test` runs the tests of the current checkout (main checkout or git worktree) in a one-off container against its own MariaDB test database. It overrides generic `php artisan test` / `composer test` instructions: host PHP has no access to the database.

=== .ai/ui-design-preview rules ===

# UI Design Preview

When changing any user-facing UI or visual design, keep the relevant preview surface in sync during the same task.

The preview must account for every affected design area in the project, including pages, layouts, navigation, forms, tables, cards, modals, filters, empty states, loading states, error states, responsive states, and dark mode when the project supports it.

Use the preview surface that exists for the current project: a Blade preview page, Storybook story, component playground, screenshot fixture, or the affected view itself. Do not introduce domain-specific examples unless the project actually has that domain.

Every project should expose the design preview through a stable route or page, such as `/design-preview`, in local/development or behind appropriate access control. If no dedicated preview exists yet, create one or update the affected route directly before treating the UI change as complete.

=== .ai/worktrees rules ===

# Git Worktrees (Orca, Claude Code, Codex)

Parallel agents work in git worktrees: Orca creates them in `~/orca/workspaces/<repo>/<name>`, Claude Code in `.claude/worktrees/<name>`. You are in a worktree when `git rev-parse --git-dir` differs from `git rev-parse --git-common-dir`.

- The main checkout's running stack (its `APP_URL`, Vite port, containers and Boost MCP there) serves the MAIN checkout's code. Never use it to verify worktree changes.
- A worktree gets its own `.env` from `make worktree-setup` (Orca runs it through `orca.yaml` before the agent starts): `WORKTREE_MANAGED=1`, its own `DOCKER_PROJECT_NAME`, ports, databases and database user. Never copy or symlink the main `.env`, never run `make init` in a worktree, never point `DOCKER_PROJECT_NAME` or `docker compose -p` at the main project.
- If `.env` or `vendor/` is missing, or make says the `.env` "was not generated for this git worktree", run `make worktree-setup` and nothing else.
- `make test`, `make artisan`, `make composer` and `make shell` run in one-off containers that mount the current checkout, so they test and change THIS worktree. Host `php artisan` / `php artisan test` do not work.
- `make test` uses the worktree's own test database, so parallel agents do not collide. "No running database": ask the user to start the main stack, or run `make db-up` (only this worktree's db and redis).
- After adding migrations: `make artisan artisan_args="migrate"` (the worktree's own database). Boost MCP answers for this worktree's code and database.
- Pint: `vendor/bin/pint --dirty --format agent` on the host (the worktree has its own `vendor/`).
- UI checks: `make up` starts an isolated stack at `APP_URL` from the worktree's `.env` with an empty database (`make artisan artisan_args="db:seed"` to fill it); `make down` when finished.
- After changing `Dockerfile` or `docker/app/*`: `make build` in the worktree (one-off containers otherwise use the main checkout's image).
- `docs/changelog.md`: add your entry without reordering existing ones; expect merge conflicts there.
- After a branch is merged, migrations do not run by themselves in the main checkout: `make artisan artisan_args="migrate"` there.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v13
- laravel/octane (OCTANE) - v2
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== octane/core rules ===

# Octane

- Octane boots the application once and reuses it across requests, so singletons persist between requests.
- The Laravel container's `scoped` method may be used as a safe alternative to `singleton`.
- Never inject the container, request, or config repository into a singleton's constructor; use a resolver closure or `bind()` instead:

```php
// Bad
$this->app->singleton(Service::class, fn (Application $app) => new Service($app['request']));

// Good
$this->app->singleton(Service::class, fn () => new Service(fn () => request()));
```

- Never append to static properties, as they accumulate in memory across requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
