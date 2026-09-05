# Contributing

Thanks for considering a contribution.

## Local setup

1. Install dependencies:
   `composer install`
2. Enable module in your Drupal test site.
3. Run quality checks before opening a PR:
   - `composer test:smoke`
   - `composer lint`
   - `composer analyze`

## Pull request checklist

- Keep changes focused and documented.
- Add or update tests when behavior changes.
- Update `README.md` when CLI flags, defaults, or safeguards change.
- Update `CHANGELOG.md` under `Unreleased`.

## Coding standards

- Follow Drupal coding standards (enforced by `phpcs`).
- Prefer strict types and explicit exceptions.
- Keep seed operations safe-by-default.

