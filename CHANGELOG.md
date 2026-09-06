# Changelog

All notable changes to this project are documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning.

## [Unreleased]

### Added
- Paragraph generation across all writable reference fields, with installed-type discovery, field inclusion/exclusion settings, profile filtering, cardinality, and required nesting within the depth limit.
- Deduplicated required-field and Paragraph configuration warnings in Drush output and JSON run reports, including dry runs.
- Paragraph workflow tests covering multiple fields, type restrictions, cardinality, required nesting, depth limits, and simulation without writes.
- `mock:setup` assistant to select a content type, enable a profile, and explicitly create a missing minimal type.
- Generation of supported empty custom fields on nodes and Paragraphs using field settings.
- Incremental run journal and failed-run reset instructions for recovery after partial generation.
- Service tests for field configuration boundaries, setup, and failed-run cleanup.

- Initial quality and release scaffolding (`phpcs`, `phpstan`, `phpunit`, CI workflow).
- Package governance files (`LICENSE`, `CONTRIBUTING.md`, `CHANGELOG.md`).
- `mock:doctor` command to validate seeder prerequisites and safeguards.
- `--seed` option on `mock:seed` for reproducible Faker/RNG generation.
- `--json=1` output mode for `mock:seed` and `mock:doctor` reports.

### Fixed
- Clear the last-run pointer after resetting the referenced run.
