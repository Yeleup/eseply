# Laravel Docker Template

Reusable Docker setup for Laravel projects that run with Laravel Octane and FrankenPHP.

## What is included

- `Dockerfile` for PHP 8.4, Composer, Node build assets, and FrankenPHP.
- `docker-compose.yml` for app, queue, scheduler, MariaDB, and Redis.
- `docker-compose.override.yml` for local development with bind mounts and Vite.
- `Makefile` with common commands for build, start, logs, tests, dumps, and deploy.
- `.env.docker.example` with Docker-specific variables you can copy into the target project's `.env`.

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
docker compose -f docker-compose.yml -f docker-compose.override.yml run --build --rm --no-deps --entrypoint composer app require laravel/octane --no-scripts
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps --entrypoint php app artisan package:discover --ansi
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm --no-deps --entrypoint php app artisan octane:install --server=frankenphp
```

Using `--no-scripts` avoids project-specific Composer hooks blocking Octane installation. For example, a project may have `@php artisan boost:update --ansi` in `post-update-cmd`; that command requires Boost to be installed first with `php artisan boost:install`.

## Environment variables

`make init` adds missing variables from `.env.docker.example` to the target project's `.env`. Existing `.env` values are not overwritten.

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

## Production mode

Set `APP_ENV=production` in `.env` and run:

```bash
make build
```

For deploy on a server:

```bash
make deploy
```
