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
