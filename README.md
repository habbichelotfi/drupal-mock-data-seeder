# Drupal Mock Data Seeder

Development module that generates realistic Drupal content trees for load/UI testing:

- nodes
- nested paragraphs
- taxonomy references
- media references

## Install

```bash
composer require fakerphp/faker
drush en drupal_mock_data_seeder -y
drush cset drupal_mock_data_seeder.settings enabled 1 -y
drush cr
```

## Quick start

Dry run:

```bash
drush mock:seed --profile=default --dry-run=1
```

Generate data:

```bash
drush mock:seed --profile=default --bundle=page --count=20 --depth=3 --locale=fr_FR
```

Rollback last run (or pass `--run-id`):

```bash
drush mock:reset --run-id=20260901_102030_abcd1234
```

## Safety guards

`config/install/drupal_mock_data_seeder.settings.yml` includes:

- `enabled`: module must be explicitly enabled.
- `safeguards.max_count`: caps node creation per run.
- `safeguards.blocked_envs`: blocks run in matching env values.
- `safeguards.env_var_names`: env vars used for detection.
- `safeguards.require_run_id_for_reset`: requires explicit run id for reset.

Override safety explicitly (use with care):

```bash
drush mock:seed --profile=default --count=500 --force=1
drush mock:reset --force=1
```

## Validate local code

```bash
bash tests/bin/smoke.sh
```

## Development quality checks

Install dev dependencies first:

```bash
composer install
```

Run all quality checks:

```bash
composer qa
```

Or run each command individually:

```bash
composer lint
composer analyze
composer test
```

## Contributing

- Read `CONTRIBUTING.md` before opening a pull request.
- Add tests for behavior changes.
- Document user-facing changes in `CHANGELOG.md`.

