# Laravel Oracle Database Driver

[![PHP Version](https://img.shields.io/packagist/php-v/jfelder/oracledb.svg?style=flat-square)](https://packagist.org/packages/jfelder/oracledb)
[![Latest Version](https://img.shields.io/packagist/v/jfelder/oracledb.svg?style=flat-square)](https://packagist.org/packages/jfelder/oracledb)
[![Total Downloads](https://img.shields.io/packagist/dt/jfelder/oracledb.svg?style=flat-square)](https://packagist.org/packages/jfelder/oracledb)
[![License](https://img.shields.io/packagist/l/jfelder/oracledb.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/jfelder/Laravel-OracleDB/actions/workflows/tests.yml/badge.svg)](https://github.com/jfelder/Laravel-OracleDB/actions/workflows/tests.yml)
[![Coverage](https://github.com/jfelder/Laravel-OracleDB/actions/workflows/coverage.yml/badge.svg)](https://github.com/jfelder/Laravel-OracleDB/actions/workflows/coverage.yml)
[![Codecov](https://codecov.io/github/jfelder/Laravel-OracleDB/graph/badge.svg?token=wRWuboe79d)](https://codecov.io/github/jfelder/Laravel-OracleDB)

OracleDB is an Oracle Database driver for Laravel 13. It extends
[Illuminate Database](https://github.com/illuminate/database) and provides a PDO-compatible adapter built on PHP's
[OCI8 extension](https://www.php.net/manual/en/book.oci8.php).

Please [report bugs through GitHub Issues](https://github.com/jfelder/Laravel-OracleDB/issues).

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Known Limitations](#known-limitations)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Requirements

- Laravel 13 (`illuminate/* ^13.0`)
- PHP 8.3 or later (`^8.3`)
- The PHP [OCI8 extension](https://www.php.net/manual/en/oci8.installation.php)
- Oracle Client or Oracle Instant Client libraries compatible with the OCI8 extension

Package major versions follow Laravel major versions. Use OracleDB 13.x with Laravel 13.x. CI currently tests PHP 8.3,
8.4, and 8.5, including the lowest and latest supported Laravel 13 dependency sets.

> **Important:** OracleDB no longer supports the
> [PDO_OCI extension](https://www.php.net/manual/en/ref.pdo-oci.php). OCI8 is the only supported Oracle transport.

## Installation

Install the Laravel 13-compatible package with Composer:

```sh
composer require jfelder/oracledb:^13.0
```

Laravel's package auto-discovery automatically registers OracleDB's service provider.

Publish the package configuration file:

```sh
php artisan vendor:publish --tag=oracledb-config
```

This copies the package configuration to `config/oracledb.php`.

## Configuration

The published `config/oracledb.php` file defines a connection named `oracle` and is merged into Laravel's
`database.connections` configuration. A typical service-name connection uses:

```dotenv
DB_CONNECTION=oracle
DB_HOST=127.0.0.1
DB_PORT=1521
DB_SERVICE_NAME=FREEPDB1
DB_USERNAME=app_user
DB_PASSWORD=secret
DB_CHARSET=AL32UTF8
```

Set `DB_CHARSET` to the character set required by your Oracle client and database. The package default is
`WE8ISO8859P1`.

You may configure the connection in either of these ways:

- Set `DB_TNS` to a complete TNS descriptor. When it is present, the host, port, service name, and SID settings are not
  used to build the connection descriptor.
- Set `DB_HOST`, `DB_PORT`, and `DB_SERVICE_NAME` for a service-name connection.
- Leave `DB_SERVICE_NAME` empty and set `DB_DATABASE` to connect using an SID.

The `quoting` option in `config/oracledb.php` defaults to `false`, allowing Oracle to apply its normal identifier
casing. Set it to `true` when your schema relies on case-sensitive quoted identifiers.

To define multiple Oracle connections, copy the `oracle` connection entry, give each copy a unique name, and use
distinct environment-variable names for each connection.

### NLS Session Parameters

The previous `date_format` option has been replaced by Oracle NLS session parameters. Defaults are defined by the
package and may be overridden through the `session_parameters` array in `config/oracledb.php` or the corresponding
environment variables, such as `NLS_DATE_FORMAT`.

These settings affect Eloquent date attributes and Query Builder operations that bind Carbon instances.

| Parameter | Default value |
|---|---|
| `NLS_TIME_FORMAT` | `HH24:MI:SS` |
| `NLS_DATE_FORMAT` | `YYYY-MM-DD HH24:MI:SS` |
| `NLS_TIMESTAMP_FORMAT` | `YYYY-MM-DD HH24:MI:SS` |
| `NLS_TIMESTAMP_TZ_FORMAT` | `YYYY-MM-DD HH24:MI:SS TZH:TZM` |
| `NLS_NUMERIC_CHARACTERS` | `.,` |

## Basic Usage

Once the Oracle connection is configured, use Laravel's `DB` facade normally:

```php
use Illuminate\Support\Facades\DB;

$results = DB::select('select * from users where id = ?', [1]);
```

This example assumes `oracle` is Laravel's default database connection. `select()` returns an array of result rows.

Select an explicit connection when Oracle is not the default:

```php
$results = DB::connection('oracle')->select(
    'select * from users where id = ?',
    [1],
);
```

### Inserting Records With a Generated ID

```php
$id = DB::connection('oracle')->table('users')->insertGetId(
    ['email' => 'john@example.com', 'votes' => 0],
    'userid',
);
```

For this driver, Laravel's second `insertGetId()` argument (named `$sequence` in Laravel's API) identifies the column
returned by Oracle's `RETURNING` clause. It defaults to `id`. The database must populate this column through an
identity, trigger, default, or equivalent mechanism.

See the [Laravel database documentation](https://laravel.com/docs/13.x/database) for general Query Builder and
connection usage.

## Known Limitations

Some features available in Laravel's first-party database drivers are not implemented by this package. The lists below
distinguish operations that throw an unsupported-operation exception from fluent options that are accepted but do not
affect the generated Oracle SQL.

Pull requests are welcome for implementing these features or expanding this list.

### Unsupported: Query Builder

- Group limiting via `$query->groupLimit($value, $column)`. Laravel uses this to limit eagerly loaded results per
  parent.
- Lateral joins via `joinLateral()` or `leftJoinLateral()`.
- Case-insensitive `LIKE` operations such as
  `DB::table('users')->whereLike('email', '%foo%', caseSensitive: false)->get()`. Use an `UPPER(column) LIKE ?`
  expression instead.
- `DB::table('users')->insertOrIgnore(['email' => 'foo'])`.
- `DB::table('users')->insertOrIgnoreReturning([['email' => 'foo']], ['id'])`.
- `DB::table('users')->insertOrIgnoreUsing(['email'], DB::table('staging_users')->select('email'))`.
- Calling `insertGetId()` with an empty values array. Non-empty inserts are supported.
- Upserts via `DB::table('users')->upsert($values, 'email')`.
- Deleting with a join.
- Deleting with an order or limit.
- JSON query and update operations, including JSON path access, containment, overlap, key-existence, and length
  operations.
- Full-text queries such as `DB::table('users')->whereFullText('description', 'Hello World')`.

### Unsupported: Eloquent

- Setting `$guarded` to a non-empty list, for example `protected $guarded = ['id'];`. Models must either inherit
  Laravel's default guarded configuration or set `$guarded` to an empty array. A custom non-empty list may cause
  Eloquent to request unsupported column-listing metadata.
- Limiting the number of eagerly loaded results per parent, such as
  `User::with(['posts' => fn ($query) => $query->limit(3)])->get()`.

### Unsupported: Schema Builder

- Schema dumping through `php artisan schema:dump` or `php artisan schema:dump --prune`.
- Creating databases through `Schema::createDatabase('example')`.
- Dropping databases through `Schema::dropDatabaseIfExists('example')`.
- Schema inspection methods that retrieve columns, indexes, foreign keys, or user-defined types. This includes
  `getColumns()`, `getColumnListing()`, `getIndexes()`, `getForeignKeys()`, `getTypes()`, `hasColumn()`, `hasColumns()`,
  `hasIndex()`, `hasForeignKey()`, and conditional helpers built on these methods.
- Renaming an index through `$blueprint->renameIndex('foo', 'bar')`.
- Creating spatial indexes through `$blueprint->spatialIndex('coordinates')` or
  `$blueprint->point('coordinates')->spatialIndex()`.
- Creating generated columns with `virtualAs`, `storedAs`, or `generatedAs`.
- Creating geometry or geography columns.
- Creating vector columns or vector indexes.
- Ensuring a vector extension exists through `Schema::ensureVectorExtensionExists()`.

### Accepted But Currently No-Op

- Table collation through `$blueprint->collation('BINARY_CI')`.
- Column collation through `$blueprint->string('some_column')->collation('BINARY_CI')`.
- The `$blueprint->temporary()` flag. It is ignored, so the generated statement creates a regular table.
- Index algorithms passed as the third argument to `$blueprint->index(['foo', 'bar'], 'baz', 'hash')`.
- Starting values on identity columns through `$blueprint->increments('id')->startingValue(1000)`.

### Supported With Limitations

- `json()` and `jsonb()` schema columns are stored as `CLOB`. Query Builder JSON operators remain unsupported.

## Testing

Install development dependencies:

```sh
composer install
```

If OCI8 is not installed locally, run the portable portion of the test suite:

```sh
vendor/bin/phpunit --exclude-group oci8
```

If OCI8 is available, run the full suite:

```sh
vendor/bin/phpunit
```

Verify compatibility with the lowest supported dependency versions:

```sh
composer update --prefer-lowest --prefer-stable --prefer-dist --no-progress --no-interaction
vendor/bin/phpunit
```

Check code style:

```sh
vendor/bin/pint --test
```

This repository intentionally does not commit `composer.lock` because it is a library. CI resolves both the lowest and
current stable dependency sets.

## Contributing

Bug reports and pull requests are welcome. When reporting a database issue, include the Laravel, PHP, OCI8, Oracle
Client, and Oracle Database versions, together with a minimal query or migration that reproduces the behavior.

Use [GitHub Issues](https://github.com/jfelder/Laravel-OracleDB/issues) for confirmed bugs and compatibility reports.

## License

OracleDB is open-source software licensed under the [MIT License](LICENSE).
