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
- Docker-related README instructions

Keep the template reusable. Do not copy project-specific secrets, local machine paths, app names, generated files, or one-off values into `laravel-docker-template` unless the change is intentionally part of the reusable template.
