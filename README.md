# Laravel Docker Template

Reusable Docker setup for Laravel projects that run with Laravel Octane and FrankenPHP.

## What is included

- `Dockerfile` for PHP 8.4, Composer, Node build assets, and FrankenPHP.
- `docker-compose.yml` for app, queue, scheduler, MariaDB, and Redis.
- `docker-compose.override.yml` for local development with bind mounts and Vite.
- `Makefile` with common commands for build, start, logs, tests, dumps, and deploy.
- `.env.docker.example` with Docker-specific variables you can copy into the target project's `.env`.
- `docker/worktree/worktree.sh` and `orca.yaml` for running several git worktrees (parallel coding agents) side by side.

## Copy into a project

Copy the contents of this folder into the root of a Laravel project:

```bash
cp -a laravel-docker-template/. /path/to/your-laravel-project/
```

Do not copy the folder itself if you want the Docker files to work from the project root.

## Required project dependency

The target Laravel project must have Octane installed with FrankenPHP:

```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp
```

If you want to install it through Docker after copying the template:

```bash
export DOCKER_PROJECT_NAME=your-project
docker compose -f docker-compose.yml -f docker-compose.override.yml run --build --rm --no-deps --entrypoint composer app require laravel/octane --no-scripts
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps --entrypoint php app artisan package:discover --ansi
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps --entrypoint php app artisan octane:install --server=frankenphp
```

`docker-compose.yml` requires `DOCKER_PROJECT_NAME`: there is no shared default, so two projects (or two checkouts of one project) never end up in the same compose project by accident.

Using `--no-scripts` avoids project-specific Composer hooks blocking Octane installation. For example, a project may have `@php artisan boost:update --ansi` in `post-update-cmd`; that command requires Boost to be installed first with `php artisan boost:install`.

## Environment variables

`make init` adds missing variables from `.env.docker.example` to the target project's `.env`. Existing `.env` values are not overwritten. An empty `DOCKER_PROJECT_NAME` is set to the project directory name.

After that, change the project-specific values:

```env
DOCKER_PROJECT_NAME=your-project
APP_NAME="Your Project"
APP_PORT=8500
APP_URL=http://localhost:8500

DB_DATABASE=your_project
MARIADB_TEST_DATABASE=your_project_testing
FORWARD_DB_PORT=3350
VITE_PORT=5174
```

Use different ports when running multiple projects at the same time.

## Start locally

```bash
make init
make build
```

Open the project at the `APP_URL` value from `.env`.

Useful commands:

```bash
make up
make down
make logs
make ps
make shell
make test
```

`make test`, `make artisan`, `make composer` and `make shell` run in one-off containers (`docker compose run --rm`) that mount the current checkout, so only the database has to be running (`make db-up` starts just `db` and `redis`). `make up`, `make build`, `make down` and the other stack commands refuse to touch a compose project whose containers were created from another directory. Volumes carry no such owner, so keep `DOCKER_PROJECT_NAME` unique per checkout.

## Git worktrees (Orca, Claude Code, `git worktree add`)

Every git worktree needs its own `.env`: a copy of the main `.env` would make the worktree's `make up` take over the main checkout's containers and `make test` test the main checkout's code. Run this in a new worktree:

```bash
make worktree-setup
```

It (see `docker/worktree/worktree.sh`):

- writes the worktree `.env` from the main checkout's `.env` with its own `DOCKER_PROJECT_NAME` (`<main>-wt-<name>-<id>`), `APP_PORT`/`APP_URL`, `FORWARD_DB_PORT`, `VITE_PORT` (a free slot in a per-project block of 20000-29900, or `WORKTREE_PORT_BASE`), databases (`<db>_wt_<name>_<id>` and `..._test`), a database user `wt_<id>` with a random password, and `SESSION_COOKIE`;
- copies `vendor/` from the main checkout (a real copy, never a symlink or hardlinks) and runs `composer install`;
- creates the databases and the user on the main checkout's MariaDB and runs the migrations.

After that, in the worktree:

- `make test`, `make artisan`, `make composer`, `make shell` and the Boost MCP server (`make boost-mcp`) use one-off containers on the main checkout's network with the worktree's own databases and user, and with file cache, file sessions and the sync queue instead of the shared Redis and queue worker;
- `make test` creates the worktree databases if needed (for example after the main checkout's database volume was recreated), so parallel worktrees never share a test database;
- `make up` starts a fully isolated stack (own containers, ports, volumes and an empty database); `make db-up` starts only its own `db` and `redis`, for when the main stack is down;
- `make worktree-archive` removes the worktree's containers, volumes, image tag, databases and database user; run it before deleting the worktree.

`make worktree-prune` (in any checkout) lists leftovers of deleted worktrees; `make worktree-prune CONFIRM=yes` removes them.

### Orca

`orca.yaml` runs `make worktree-setup` when Orca creates a workspace (the agent waits for it) and the cleanup when Orca deletes it. In Orca's repository settings:

- set **Command source** to **orca.yaml only**, or to **Run both** with a local setup script that fails for branches without this setup: `test -f docker/worktree/worktree.sh || { echo 'Branch has no worktree setup: merge the main branch, then run make worktree-setup.' >&2; exit 1; }`;
- turn on **Wait for setup to complete before starting agent**;
- leave **Worktree Shared Paths** empty (never share `.env`, `vendor` or `node_modules`);
- delete workspaces from the desktop app, or pass `--run-hooks` to `orca worktree remove`, otherwise the cleanup does not run.

Worktree support needs Linux tools: `flock`, `ss`, `realpath`, `cp --reflink`.

## Production mode

Set `APP_ENV=production` in `.env` and run:

```bash
make build
```

For deploy on a server:

```bash
make deploy
```

Upgrading a server set up before `DOCKER_PROJECT_NAME` became required: if its `.env` has no `DOCKER_PROJECT_NAME`, its containers and volumes belong to the old default project `laravel-app`. Add `DOCKER_PROJECT_NAME=laravel-app` to `.env` before `make deploy` (`make init` does this automatically while those containers exist); any other name starts a new, empty project.
