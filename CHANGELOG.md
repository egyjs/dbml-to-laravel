# Changelog

All notable changes to `dbml-to-laravel` will be documented in this file.

## [Unreleased]

### Added
- New `generate:dbml` command pipeline that uses the bundled Node-based parser (`bin/parse-dbml.js`) and rich schema objects (`src/Parsing/Dbml/*`) to generate models, migrations, enums, indexes, and relationships end-to-end (`src/Commands/GenerateFromDbml.php`).
- Bundled parser runtime plus npm tooling (`package.json`, `package-lock.json`, `bin/parse-dbml.runtime.cjs`) with contributor instructions for rebuilding.
- Feature tests and fixtures that cover successful generation and error handling (`tests/Feature/GenerateFromDbmlCommandTest.php`, `tests/Fixtures/simple.dbml`).

### Changed
- Model and migration stubs now expose additional placeholders so generated migrations include column definitions, indexes, and relationship hints (`stubs/*.stub`).
- Documentation and contribution guides call out the Node.js requirement and how to refresh the parser bundle (`README.md`, `CONTRIBUTING.md`).
- CI workflows install the new prerequisites and run the updated test suite (`.github/workflows/*.yml`).


