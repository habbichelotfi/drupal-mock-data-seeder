# Drupal Mock Data Seeder

Development module for generating realistic Drupal content trees for local
development, UI testing, and load testing. It can create or reuse:

- nodes;
- nested Paragraphs, when the Paragraphs module is available;
- taxonomy references and optional terms;
- media references and optional remote-video media.

This module is intended for non-production environments. It is disabled by
default and includes environment, count, and rollback safeguards.

## Requirements

- PHP 8.1 or newer according to `composer.json`;
- Drupal 10.3+ or Drupal 11;
- Composer;
- Drush for the command-line examples;
- optional: Paragraphs and Media modules for those entity types.

The current committed `composer.lock` resolves Drupal 11.4.6, which requires
PHP 8.3 or newer. Use PHP 8.3+ with the lock file, or regenerate the lock file
under PHP 8.1/8.2 with dependency versions compatible with that runtime.

## Installation

From the Drupal project root containing this package:

```bash
composer install --no-interaction --prefer-dist
drush en drupal_mock_data_seeder -y
drush cset drupal_mock_data_seeder.settings enabled 1 -y
drush cr
```

Review the generated configuration before enabling the module in any shared
environment. The module's default configuration is in
`config/install/drupal_mock_data_seeder.settings.yml`.

## Quick start

Run a dry-run first. It validates the profile and bundle without saving
entities:

```bash
drush mock:seed --profile=default --dry-run=1
```

Generate 20 nodes using the `page` bundle, three levels of nesting, and French
fake data:

```bash
drush mock:seed \
  --profile=default \
  --bundle=page \
  --count=20 \
  --depth=3 \
  --locale=fr_FR
```

### Reproducible runs

Pass an integer seed to make Faker and PHP random choices repeatable:

```bash
drush mock:seed --profile=default --count=20 --seed=4242
```

The seed is included in the run result and stored run metadata.

### JSON reports

Use `--json=1` for scripts and CI integrations. The report includes the run ID,
profile, bundle, requested count, depth, locale, seed, duration, and entity
statistics:

```bash
drush mock:seed --profile=default --count=10 --json=1
```

## Diagnostics

Check configuration and runtime prerequisites without creating entities:

```bash
drush mock:doctor --profile=default
```

The diagnostic checks include:

- whether the seeder is enabled;
- whether the requested profile exists;
- whether the target node bundle exists;
- whether the current environment is blocked by safeguards;
- whether the system temporary directory is writable.

For automation, request JSON output:

```bash
drush mock:doctor --profile=default --json=1
```

## Rollback

Each non-dry run stores the IDs of entities it created. Roll back a specific
run with its reported ID:

```bash
drush mock:reset --run-id=20260901_102030_abcd1234
```

If the configured safeguard requires a run ID, omitting it fails safely. To
roll back the last stored run explicitly:

```bash
drush mock:reset --force=1
```

## Safety configuration

The module is disabled by default. Relevant settings are:

- `enabled`: explicitly enables seeding;
- `safeguards.max_count`: maximum root nodes per run;
- `safeguards.blocked_envs`: environment values that block execution;
- `safeguards.env_var_names`: environment variables inspected;
- `safeguards.require_run_id_for_reset`: requires an explicit rollback ID.

Use `--force=1` only when you understand the consequences:

```bash
drush mock:seed --profile=default --count=500 --force=1
drush mock:reset --force=1
```

## Development and quality checks

Install development dependencies and run the complete local quality suite:

```bash
composer install
composer qa
```

Individual checks:

```bash
composer test:smoke
composer lint
composer analyze
composer test
```

The GitHub Actions workflow runs the smoke test, Drupal coding standards, and
PHPStan analysis. Kernel tests require a correctly configured Drupal test
environment.

## Contributing

See `CONTRIBUTING.md` before opening a pull request. Behavior changes should
include tests and corresponding documentation updates. User-facing changes
should be recorded in `CHANGELOG.md`.

## License

This project is released under the MIT License. See `LICENSE`.
