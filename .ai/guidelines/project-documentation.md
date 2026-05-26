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
