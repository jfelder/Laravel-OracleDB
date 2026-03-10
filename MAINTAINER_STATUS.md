# Maintainer Status

Last updated: 2026-03-10

## Purpose

This document is a maintainer-oriented snapshot of the repository as it exists today. It is meant to complement, not replace, the user-facing guidance in [`README.md`](/Users/JFELDER/projects/Laravel-OracleDB/README.md).

## Project Summary

Laravel-OracleDB is a Laravel 12 Oracle database driver package published as `jfelder/oracledb`. The package depends on PHP 8.2+ and Illuminate database/support/pagination 12.x, as defined in [`composer.json`](/Users/JFELDER/projects/Laravel-OracleDB/composer.json).

The package integrates with Laravel through [`src/Jfelder/OracleDB/OracleDBServiceProvider.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OracleDBServiceProvider.php), which:

- publishes the Oracle config file
- merges the package connection config into `database.connections`
- registers an `oracle` connection resolver
- applies Oracle session parameters after connection creation

This version is documented in the changelog as the Laravel 12 upgrade release [`12.0.0`](https://github.com/jfelder/Laravel-OracleDB/compare/11.0.2...v12.0.0), dated 2026-02-18.

## Current Architecture

The codebase is organized into four main areas:

### 1. Laravel integration and connection behavior

- [`src/Jfelder/OracleDB/OracleDBServiceProvider.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OracleDBServiceProvider.php)
- [`src/Jfelder/OracleDB/OracleConnection.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OracleConnection.php)
- [`src/config/oracledb.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/config/oracledb.php)

Responsibilities:

- config publication and merge behavior
- connection bootstrapping
- query/schema grammar registration
- Oracle-specific `insertGetId` support through `returning ... into ?`
- Oracle session configuration via `ALTER SESSION SET ...`
- explicit rejection of unsupported schema dumping

### 2. Oracle connection creation

- [`src/Jfelder/OracleDB/Connectors/OracleConnector.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Connectors/OracleConnector.php)

Responsibilities:

- accepts `oracle`, `oci`, and `oci8` driver names
- validates `charset`
- builds DSN/TNS strings
- supports single-host and multi-host address blocks
- supports Oracle failover and load balancing options

### 3. Oracle query and schema support

- [`src/Jfelder/OracleDB/Query/Grammars/OracleGrammar.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Query/Grammars/OracleGrammar.php)
- [`src/Jfelder/OracleDB/Query/OracleBuilder.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Query/OracleBuilder.php)
- [`src/Jfelder/OracleDB/Query/Processors/OracleProcessor.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Query/Processors/OracleProcessor.php)
- [`src/Jfelder/OracleDB/Schema/Grammars/OracleGrammar.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Schema/Grammars/OracleGrammar.php)
- [`src/Jfelder/OracleDB/Schema/OracleBuilder.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Schema/OracleBuilder.php)
- [`src/Jfelder/OracleDB/Schema/OracleBlueprint.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Schema/OracleBlueprint.php)

Responsibilities:

- Oracle-specific SQL generation for query builder operations
- Oracle-specific schema SQL generation
- Oracle-compatible insert, insert-get-id, exists, offset/limit, and locking behavior
- custom schema parsing behavior
- explicit rejection of unsupported schema builder operations such as column listing

### 4. OCI-backed PDO compatibility layer

- [`src/Jfelder/OracleDB/OCI_PDO/OCI.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OCI_PDO/OCI.php)
- [`src/Jfelder/OracleDB/OCI_PDO/OCIStatement.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OCI_PDO/OCIStatement.php)
- [`src/Jfelder/OracleDB/OCI_PDO/OCIException.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OCI_PDO/OCIException.php)

Responsibilities:

- wrap OCI8 functions in PDO/PDOStatement-like behavior
- manage connection lifecycle and transactions
- handle parameter binding and fetch behavior
- bridge Laravel’s PDO expectations onto OCI8

This layer is the highest-risk part of the package because it depends on OCI8 resources, OCI constants, and behavior that is hard to exercise consistently outside Oracle-enabled environments.

## User-Facing Support Surface

The public support surface is currently described in [`README.md`](/Users/JFELDER/projects/Laravel-OracleDB/README.md). Broadly:

- installation is via Composer plus `vendor:publish`
- connection configuration is driven by `config/oracledb.php`
- Oracle session NLS parameters are set automatically and can be overridden through environment configuration
- normal query builder and DB facade usage is intended to feel Laravel-native

Recent schema-surface additions that are now implemented and tested include:

- `dropAllTables()`
- `dropAllViews()`
- `dropAllTypes()`
- `timestamp(...)->useCurrent()`
- table comments and column comments
- `dateTimeTz()`, `timestampTz()`, and `timestampsTz()`
- `ipAddress()` and `macAddress()`
- `uuid()` and `foreignUuid()`
- `json()` and `jsonb()` as storage types backed by `CLOB`

JSON query operators remain unsupported even though schema-level JSON storage is now available.

## Testing Status

The repository has a substantial PHPUnit suite under [`tests/`](/Users/JFELDER/projects/Laravel-OracleDB/tests). The most complete coverage is around SQL generation and builder behavior, but the suite meaningfully depends on an OCI-capable runtime.

Observed results on 2026-03-10:

### OCI-enabled Sail environment

- command run: `sail vendor/bin/phpunit`
- result: 470 tests, 1114 assertions, all passing
- runtime: PHP 8.3.30, PHPUnit 12.5.8, PCOV 1.0.12

Coverage summary reported by PHPUnit in Sail:

- classes: 66.67% (8/12)
- methods: 96.30% (130/135)
- lines: 89.71% (497/554)

Interpretation:

- the project test suite is effectively healthy in its intended local container environment
- the OCI layer, connector, connection, query grammar, and schema grammar are all exercised well
- the suite is green after the recent schema-surface additions

### Bare workspace environment without OCI support

- command run: `./vendor/bin/phpunit --exclude-group oci8`
- result: 360 tests, 933 assertions, all passing
- runtime: PHP 8.3.29, PHPUnit 12.5.8, Xdebug 3.3.1

Interpretation:

- the portable non-OCI subset is now intentionally supported through PHPUnit grouping
- OCI-native tests are isolated behind the `oci8` group and are skipped when the extension or required constants are unavailable

Maintainer takeaway:

- the Sail environment is the correct baseline for judging package health
- contributors without OCI8 can still run a meaningful fast suite from the command line
- portability outside OCI-enabled environments is improved, though OCI-native coverage still requires an OCI-capable runtime

## Known Issues And Risks

### 1. The test suite is strongly coupled to an OCI-enabled runtime

Outside the intended Sail or OCI-enabled environment, local test failures are dominated by missing OCI-related constants such as:

- `OCI_COMMIT_ON_SUCCESS`
- `SQLT_INT`

These assumptions appear in the OCI shim layer and break tests in environments where the OCI8 extension is absent or only partially represented. This affects maintainability because it makes the suite less portable and raises the cost of validating non-Oracle changes outside the containerized project environment.

### 2. Some unsupported behavior is enforced by runtime exceptions

A number of unsupported features are not soft limitations; they are hard failures by design. Examples include:

- schema dumping in [`OracleConnection::getSchemaState()`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OracleConnection.php)
- column listing in [`OracleBuilder::getColumnListing()`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Schema/OracleBuilder.php)
- creating/dropping databases in the schema grammar

This is reasonable, but it increases the importance of keeping documentation current because framework expectations may change across Laravel releases.

### 3. Feature support now has more nuance than a simple supported/unsupported split

Some areas are now supported with explicit caveats rather than being fully unsupported. The clearest example is JSON:

- schema-level `json()` and `jsonb()` columns are supported
- both currently map to `CLOB`
- query-builder JSON operators remain unsupported

This kind of mixed support should continue to be called out explicitly in documentation and tests.

### 4. Laravel compatibility pressure will continue

This package tracks Illuminate internals closely. Changes in query grammar hooks, schema grammar contracts, builder behavior, or connection expectations in future Laravel releases can cause breakage even when package code has not changed substantially.

## Immediate Maintenance Priorities

### Priority 1: Improve test portability outside the OCI-enabled environment

The project now supports a meaningful non-OCI subset via `--exclude-group oci8`, but there is still room to improve portability. Options include:

- defining safe fallback constants in test bootstrap or mocks when OCI8 is absent
- isolating OCI-native behavior into tests that are skipped when OCI8 is unavailable
- separating pure SQL-generation tests from extension-dependent integration tests more explicitly

Further work here would make refactoring and bug fixes even easier to validate outside OCI-enabled environments and in any future CI jobs that do not ship OCI support.

### Priority 2: Keep the support matrix and README synchronized as features land

Review [`README.md`](/Users/JFELDER/projects/Laravel-OracleDB/README.md) against current code and tests, especially for:

- `insertOrIgnore`
- `upsert`
- JSON operations
- `whereFulltext`
- `renameIndex()`
- spatial and generated column features

The package now has a mix of fully supported, unsupported, and supported-with-limitations features. That classification should stay explicit.

### Priority 3: Clarify supported runtime matrix

The package metadata says PHP 8.2+ and Laravel 12. Maintainers would benefit from an explicit support matrix covering:

- PHP versions tested in CI
- whether OCI8 must be present for all tests or only a subset
- expected Oracle server versions, if any
- whether contributors can run a meaningful reduced test suite without Oracle

## Suggested Near-Term Work Plan

1. Keep the `oci8` test split healthy so contributors without OCI8 can continue to run the portable suite cleanly.
2. Add or refine contributor documentation describing the difference between `vendor/bin/phpunit` and `vendor/bin/phpunit --exclude-group oci8`.
3. Keep the README support matrix current as schema/query features land.
4. Decide which of the remaining schema features are worth implementing next, especially `renameIndex()`, `geometry()`, and `geography()`.
5. Consider expanding focused coverage around any newly supported “storage-only” features so the caveats remain explicit.

## Files Maintainers Should Know First

If someone is new to maintaining this package, these are the highest-value files to read first:

- [`README.md`](/Users/JFELDER/projects/Laravel-OracleDB/README.md)
- [`CHANGELOG.md`](/Users/JFELDER/projects/Laravel-OracleDB/CHANGELOG.md)
- [`composer.json`](/Users/JFELDER/projects/Laravel-OracleDB/composer.json)
- [`src/Jfelder/OracleDB/OracleDBServiceProvider.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OracleDBServiceProvider.php)
- [`src/Jfelder/OracleDB/OracleConnection.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OracleConnection.php)
- [`src/Jfelder/OracleDB/Connectors/OracleConnector.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Connectors/OracleConnector.php)
- [`src/Jfelder/OracleDB/Query/Grammars/OracleGrammar.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Query/Grammars/OracleGrammar.php)
- [`src/Jfelder/OracleDB/Schema/Grammars/OracleGrammar.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/Schema/Grammars/OracleGrammar.php)
- [`src/Jfelder/OracleDB/OCI_PDO/OCI.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OCI_PDO/OCI.php)
- [`src/Jfelder/OracleDB/OCI_PDO/OCIStatement.php`](/Users/JFELDER/projects/Laravel-OracleDB/src/Jfelder/OracleDB/OCI_PDO/OCIStatement.php)
- [`tests/`](/Users/JFELDER/projects/Laravel-OracleDB/tests)

## Bottom Line

The package appears to be in strong shape for Laravel 12-era SQL generation and Oracle integration when evaluated in its intended Sail environment. The main maintainer concerns right now are narrower than they first appeared:

- support-surface documentation must keep pace with the expanding schema feature set
- the OCI layer and tests remain environment-sensitive outside Sail
