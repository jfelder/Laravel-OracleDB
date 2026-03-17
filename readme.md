## Laravel Oracle Database Package

### OracleDB (updated for Laravel 13)

![PHP Version](https://img.shields.io/packagist/php-v/jfelder/oracledb.svg?style=flat-square)
![Latest Version](https://img.shields.io/packagist/v/jfelder/oracledb.svg?style=flat-square)
![Total Downloads](https://img.shields.io/packagist/dt/jfelder/oracledb.svg?style=flat-square)
![License](https://img.shields.io/packagist/l/jfelder/oracledb.svg?style=flat-square)
![Tests](https://github.com/jfelder/Laravel-OracleDB/actions/workflows/tests.yml/badge.svg)
![Coverage](https://github.com/jfelder/Laravel-OracleDB/actions/workflows/coverage.yml/badge.svg)
![Codecov](https://codecov.io/github/jfelder/Laravel-OracleDB/graph/badge.svg?token=wRWuboe79d)

OracleDB is an Oracle Database Driver package for [Laravel Framework](https://laravel.com) - thanks [@taylorotwell](https://github.com/taylorotwell). OracleDB is an extension of [Illuminate/Database](https://github.com/illuminate/database) that uses the [OCI8 Functions](https://www.php.net/manual/en/ref.oci8.php) wrapped into the PDO namespace.

**Please report any bugs you may find.**

- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Unimplemented Features](#unimplemented-features)
- [License](#license)


> **IMPORTANT** This version removes the [PDO_OCI](https://www.php.net/manual/en/ref.pdo-oci.php) driver option and only uses the [OCI8 Functions](https://www.php.net/manual/en/ref.oci8.php) under the hood.


### Installation

With [Composer](https://getcomposer.org):

```sh
composer require jfelder/oracledb
```

During this command, Laravel's "Auto-Discovery" feature should automatically register OracleDB's service
provider.

Next, publish OracleDB's configuration file using the vendor:publish Artisan command. This will copy OracleDB's
configuration file to `config/oracledb.php` in your project.

```sh
php artisan vendor:publish --tag=oracledb-config
```

To finish the installation, set your environment variables (typically in your .env file) to the corresponding
env variables used in `config/oracledb.php`: such as `DB_HOST`, `DB_USERNAME`, etc.  

**Date Format Config**
The `date_format` config has been removed in favor of using the NLS_* session parameters. The default values are defined by the package and can be overridden in `config/oracledb.php` via the `session_parameters` config array or through the corresponding environment variables such as `NLS_DATE_FORMAT`. This affects all read/write operations of any Eloquent model with date fields and any Query Builder queries that utilize a Carbon instance and brings the handling of dates in line with the framework.

#### Default NLS session parameters

| Parameter | Default value |
|---|---|
| NLS_TIME_FORMAT | 'HH24:MI:SS' |
| NLS_DATE_FORMAT | 'YYYY-MM-DD HH24:MI:SS' |
| NLS_TIMESTAMP_FORMAT | 'YYYY-MM-DD HH24:MI:SS' |
| NLS_TIMESTAMP_TZ_FORMAT | 'YYYY-MM-DD HH24:MI:SS TZH:TZM' |
| NLS_NUMERIC_CHARACTERS | '.,' |


### Basic Usage
The configuration file for this package is located at `config/oracledb.php`.
In this file, you define all of your oracle database connections. If you need to make more than one connection, just
copy the example one. If you want to make one of these connections the default connection, enter the name you gave the
connection into the "Default Database Connection Name" section in `config/database.php`.

Once you have configured the OracleDB database connection(s), you may run queries using the `DB` facade as normal.

> **Note:** This driver makes OracleDB use the
[OCI8 Functions](https://www.php.net/manual/en/ref.oci8.php) under the hood. 

```php
$results = DB::select('select * from users where id = ?', [1]);
```

The above statement assumes you have set the default connection to be the oracle connection you setup in
config/database.php file and will always return an `array` of results.

```php
$results = DB::connection('oracle')->select('select * from users where id = ?', [1]);
```

Just like the built-in database drivers, you can use the connection method to access the oracle database(s) you setup
in config/oracledb.php file.

#### Inserting Records Into A Table With An Auto-Incrementing ID

```php
$id = DB::connection('oracle')->table('users')->insertGetId(
    ['email' => 'john@example.com', 'votes' => 0], 'userid'
);
```

> **Note:** When using the insertGetId method, you can specify the auto-incrementing column name as the second
parameter in insertGetId function. It will default to "id" if not specified.

See the [Laravel database documentation](https://laravel.com/docs/13.x/database) for more information.

### Unimplemented Features

Some of the features available in the first-party Laravel database drivers are not implemented in this package. The list below separates features that are currently unsupported from features whose fluent modifiers are currently accepted but have no effect on the generated SQL. Pull requests are welcome for implementing any of these features, or for expanding this list if you find any gaps not already listed.

#### Unsupported: Query Builder

- group limiting via a groupLimit clause `$query->groupLimit($value, $column);` note: this was only added to Laravel so Eloquent can limit the number of eagerly loaded results per parent
- case-insensitive `LIKE` operations such as `DB::table('users')->whereLike('email', '%foo%', caseSensitive: false)->get();` use `UPPER(column) LIKE ?` style expressions instead
- insertOrIgnore `DB::from('users')->insertOrIgnore(['email' => 'foo']);`
- insertOrIgnoreReturning `DB::from('users')->insertOrIgnoreReturning([['email' => 'foo']], ['id']);`
- insertOrIgnoreUsing `DB::from('users')->insertOrIgnoreUsing(['email'], DB::table('staging_users')->select('email'));`
- insertGetId with empty values `DB::from('users')->insertGetId([]);` (but calling with non-empty values is supported)
- upserts `DB::from('users')->upsert([['email' => 'foo', 'name' => 'bar'], ['name' => 'bar2', 'email' => 'foo2']], 'email');`
- deleting with a join `DB::from('users')->join('contacts', 'users.id', '=', 'contacts.id')->where('users.email', '=', 'foo')->delete();`
- deleting with a limit `DB::from('users')->where('email', '=', 'foo')->orderBy('id')->take(1)->delete();`
- json operations `DB::from('users')->where('items->sku', '=', 'foo-bar')->get();`
- JSON overlap operations such as `DB::from('users')->whereJsonOverlaps('options->languages', ['en', 'fr'])->get();`
- JSON key existence operations such as `DB::from('users')->whereJsonContainsKey('options->languages')->get();`
- whereFulltext `DB::table('users')->whereFulltext('description', 'Hello World');`

#### Unsupported: Eloquent

- setting `$guarded` on an Eloquent model as anything other than an empty array, for example `protected $guarded = ['id'];`. Models must either not define `$guarded` at all, or set it to an empty array. If not, Eloquent may attempt to run a column listing SQL query resulting in an exception.
- limiting the number of eagerly loaded results per parent, ie get only 3 posts per user `User::with(['posts' => fn ($query) => $query->limit(3)])->paginate();`

#### Unsupported: Schema Builder

- schema dumping such as `php artisan schema:dump` or `php artisan schema:dump --prune`
- creating databases `Schema::createDatabase('example')`
- dropping databases `Schema::dropDatabaseIfExists('example')`
- column listing operations such as `Schema::getColumnListing('users')`
- set collation on a table `$blueprint->collation('BINARY_CI')`
- set collation on a column `$blueprint->string('some_column')->collation('BINARY_CI')`
- create a private temporary table `$blueprint->temporary()`
- rename an index `$blueprint->renameIndex('foo', 'bar')`
- specify an algorithm when creating an index via the third argument `$blueprint->index(['foo', 'bar'], 'baz', 'hash')`
- create a spatial index `$blueprint->spatialIndex('coordinates')`
- create a spatial index fluently `$blueprint->point('coordinates')->spatialIndex()`
- create a generated column, like the mysql driver has `virtualAs` and `storedAs` and postgres has `generatedAs`; ie, assuming an integer type column named price exists on the table, `$blueprint->integer('discounted_virtual')->virtualAs('price - 5')`
- create a geometry column `$blueprint->geometry('coordinates')`
- create a geography column `$blueprint->geography('coordinates')`
- create a vector column `$blueprint->vector('embedding', dimensions: 1536)`
- create a vector index `$blueprint->vectorIndex('embedding')`
- ensure the vector extension exists `Schema::ensureVectorExtensionExists()`

#### Accepted But Currently No-Op

- starting values on identity columns via `$blueprint->increments('id')->startingValue(1000)`

#### Supported With Limitations

- `json()` and `jsonb()` schema columns are stored as `CLOB`, for example `$blueprint->json('payload')` or `$blueprint->jsonb('payload')`. Query Builder JSON operators remain unsupported.

### Testing

If OCI8 is not installed locally, you can still run the portable portion of the test suite:

```sh
vendor/bin/phpunit --exclude-group oci8
```

If OCI8 is available, run the full suite:

```sh
vendor/bin/phpunit
```

### License

Licensed under the [MIT License](https://cheeaun.mit-license.org).
