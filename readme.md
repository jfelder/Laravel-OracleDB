## Laravel Oracle Database Package

### OracleDB (updated for 5.1)

[![Latest Stable Version](https://poser.pugx.org/jfelder/oracledb/v/stable.png)](https://packagist.org/packages/jfelder/oracledb) [![Total Downloads](https://poser.pugx.org/jfelder/oracledb/downloads.png)](https://packagist.org/packages/jfelder/oracledb) [![Build Status](https://travis-ci.org/jfelder/Laravel-OracleDB.png)](https://travis-ci.org/jfelder/Laravel-OracleDB) [![SensioLabsInsight](https://insight.sensiolabs.com/projects/5ab2ec12-1622-4cb6-8ff0-238d0ec4028f/mini.png)](https://insight.sensiolabs.com/projects/5ab2ec12-1622-4cb6-8ff0-238d0ec4028f) [![StyleCI](https://styleci.io/repos/10234767/shield)](https://styleci.io/repos/10234767)


OracleDB is an Oracle Database Driver package for [Laravel Framework](http://laravel.com/) - thanks @taylorotwell. OracleDB is an extension of [Illuminate/Database](https://github.com/illuminate/database) that uses either the [PDO_OCI] (http://www.php.net/manual/en/ref.pdo-oci.php) extension or the [OCI8 Functions](http://www.php.net/manual/en/ref.oci8.php) wrapped into the PDO namespace.

**Please report any bugs you may find.**

- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Query Builder](#query-builder)
- [Eloquent](#eloquent)
- [Schema](#schema)
- [Migrations](#migrations)
- [License](#license)

### Installation

Add `jfelder/oracledb` as a requirement to composer.json:

```json
{
    "require": {
        "jfelder/oracledb": "5.1.*"
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
$results = DB::select('select * from users where id = ?', array(1));
```

The above statement assumes you have set the default connection to be the oracle connection you setup in
config/database.php file and will always return an 'array' of results.

```php
$results = DB::connection('oracle')->select('select * from users where id = ?', array(1));
```

Just like the built-in database drivers, you can use the connection method to access the oracle database(s) you setup
in config/oracledb.php file.

#### Inserting Records Into A Table With An Auto-Incrementing ID

```php
	$id = DB::connection('oracle')->table('users')->insertGetId(
		array('email' => 'john@example.com', 'votes' => 0), 'userid'
	);
```

> **Note:** When using the insertGetId method, you can specify the auto-incrementing column name as the second
parameter in insertGetId function. It will default to "id" if not specified.

See [Laravel Database Basic Docs](http://four.laravel.com/docs/database) for more information.

### License

Licensed under the [MIT License](http://cheeaun.mit-license.org/).
