# Contributing

Thanks for wanting to contribute to Laravel Query Doctor.

## Setup

```bash
git clone https://github.com/QDenka/laravel-query-doctor.git
cd laravel-query-doctor
composer install
```

## Development Workflow

1. Create a branch from `main`.
2. Make your changes.
3. Run the checks:

```bash
vendor/bin/phpunit           # tests
vendor/bin/phpstan analyse   # static analysis
vendor/bin/pint              # code style (auto-fixes)
```

4. Open a pull request against `main`.

## Coding Standards

- `declare(strict_types=1)` in every PHP file.
- Final classes by default.
- Typed properties, parameters, and return types everywhere.
- PHPDoc on interface methods. On concrete methods only when the signature isn't enough.
- No `mixed` unless unavoidable.

## Architecture

Read `docs/ARCHITECTURE.md` before making structural changes. The key rule: the domain layer (`src/Domain/`) has zero Laravel imports.

## Tests

- Domain tests (`tests/Unit/`) use plain PHPUnit. No TestBench.
- Infrastructure and feature tests use Orchestra TestBench.
- Add tests for new features. Update tests for changed behavior.

## Pull Request Guidelines

- Keep PRs focused. One feature or fix per PR.
- Write a clear description of what changed and why.
- If adding an analyzer, include unit tests with positive and negative cases.
- If changing config, update `docs/CONFIGURATION.md`.

## Reporting Issues

Use the issue templates on GitHub. Include your PHP version, Laravel version, and database driver.
