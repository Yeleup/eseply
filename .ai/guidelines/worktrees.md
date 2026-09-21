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
