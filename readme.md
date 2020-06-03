## Laravel Oracle Database Package

### OracleDB (updated for Laravel 6.x)

[![Latest Stable Version](https://poser.pugx.org/jfelder/oracledb/v/stable.png)](https://packagist.org/packages/jfelder/oracledb) [![Total Downloads](https://poser.pugx.org/jfelder/oracledb/downloads.png)](https://packagist.org/packages/jfelder/oracledb) [![Build Status](https://travis-ci.org/jfelder/Laravel-OracleDB.png)](https://travis-ci.org/jfelder/Laravel-OracleDB)


OracleDB is an Oracle Database Driver package for [Laravel Framework](https://laravel.com) - thanks [@taylorotwell](https://github.com/taylorotwell). OracleDB is an extension of [Illuminate/Database](https://github.com/illuminate/database) that uses either the [PDO_OCI] (https://www.php.net/manual/en/ref.pdo-oci.php) extension or the [OCI8 Functions](https://www.php.net/manual/en/ref.oci8.php) wrapped into the PDO namespace.

**Please report any bugs you may find.**

- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Unimplemented Features](#unimplemented-features)
- [License](#license)

### Installation

Add `jfelder/oracledb` as a requirement to composer.json:

```json
{
    "require": {
        "jfelder/oracledb": "6.*"
    }
}
```
And then run `composer update`

Once Composer has installed or updated your packages you need to register OracleDB. Open up `config/app.php` and find
the `providers` key and add:

```php
Jfelder\OracleDB\OracleDBServiceProvider::class,
```

Finally you need to publish a configuration file by running the following Artisan command.

```terminal
$ php artisan vendor:publish
```
This will copy the configuration file to config/oracledb.php


### Basic Usage
The configuration file for this package is located at 'config/oracledb.php'.
In this file you define all of your oracle database connections. If you need to make more than one connection, just
copy the example one. If you want to make one of these connections the default connection, enter the name you gave the
connection into the "Default Database Connection Name" section in 'config/database.php'.

Once you have configured the OracleDB database connection(s), you may run queries using the 'DB' class as normal.

#### NEW: The oci8 library in now the default library. If you want to use the pdo library, enter "pdo" as the driver and the code will automatically use the pdo library instead of the oci8 library. Any other value will result in the oci8 library being used.

```php
$results = DB::select('select * from users where id = ?', [1]);
```

The above statement assumes you have set the default connection to be the oracle connection you setup in
config/database.php file and will always return an 'array' of results.

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

See [Laravel Database Basic Docs](https://laravel.com/docs/6.x/database) for more information.

### Unimplemented Features

Some of the features available in the first-party Laravel database drivers are not implemented in this package:

#### Query Builder

- insertOrIgnore `DB::from('users')->insertOrIgnore(['email' => 'foo']);`
- insertGetId with empty values `DB::from('users')->insertGetId([]);` (but calling with non-empty values is supported)
- deleting with a join `DB::from('users')->join('contacts', 'users.id', '=', 'contacts.id')->where('users.email', '=', 'foo')->delete();`
- json operations `DB::from('users')->where('items->sku', '=', 'foo-bar')->get();`

#### Schema Builder

- drop a table if it exists `Schema::dropIfExists('some_table');`
- drop all tables, views, or types `Schema::dropAllTables()`, `Schema::dropAllViews()`, and `Schema::dropAllTypes()`
- set collation on a table `$blueprint->collation('BINARY_CI')`
- set collation on a column `$blueprint->string('some_column')->collation('BINARY_CI')`
- set comments on a table `$blueprint->comment("This table is great.")`
- set comments on a column `$blueprint->string('foo')->comment("Some helpful info about the foo column")`
- create a private temporary table `$blueprint->temporary()`
- rename an index `$blueprint->renameIndex('foo', 'bar')`
- specify an algorithm when creating an index via the third argument `$blueprint->index(['foo', 'bar'], 'baz', 'hash')`
- create a spatial index `$blueprint->spatialIndex('coordinates')`
- create a spatial index fluently `$blueprint->point('coordinates')->spatialIndex()`
- create a generated column, like the mysql driver has `virtualAs` and `storedAs` and postgres has `generatedAs`; ie, assuming an integer type column named price exists on the table, `$blueprint->integer('discounted_virtual')->virtualAs('price - 5')`
- create a json column `$blueprint->json('foo')` or jsonb column `$blueprint->jsonb('foo')` (oracle recommends storing json in VARCHAR2, CLOB, or BLOB columns)
- create a datetime with timezone column without precision `$blueprint->dateTimeTz('created_at')`, or with precision `$blueprint->timestampTz('created_at', 1)`
- create Laravel-style timestamp columns having a timezone component `$blueprint->timestampsTz()`
- create a uuid column `$blueprint->uuid('foo')` (oracle recommends a column of data type 16 byte raw for storing uuids)
- create a column to hold IP addresses `$blueprint->ipAddress('foo')` (would be implemented as varchar2 45)
- create a column to hold MAC addresses `$blueprint->macAddress('foo')` (would be implemented as varchar2 17)
- create a geometry column `$blueprint->geometry('coordinates')`
- create a geometric point column `$blueprint->point('coordinates')`
- create a geometric point column specifying srid `$blueprint->point('coordinates', 4326)`
- create a linestring column `$blueprint->linestring('coordinates')`
- create a polygon column `$blueprint->polygon('coordinates')`
- create a geometry collection column `$blueprint->geometrycollection('coordinates')`
- create a multipoint column `$blueprint->multipoint('coordinates')`
- create a multilinestring column `$blueprint->multilinestring('coordinates')`
- create a multipolygon column `$blueprint->multipolygon('coordinates')`
- create a double column without specifying second or third parameters `$blueprint->double('foo')` (but `$blueprint->double('foo', 5, 2)` is supported)

Pull requests are welcome for implementing any of these features, or for expanding this list if you find any unimplemented features not already listed.

### License

Licensed under the [MIT License](https://cheeaun.mit-license.org).
