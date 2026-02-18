<?php

namespace Jfelder\OracleDB;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Jfelder\OracleDB\Connectors\OracleConnector;

/**
 * Class OracleDBServiceProvider.
 */
class OracleDBServiceProvider extends ServiceProvider
{
    /**
     * Boot.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../../config/oracledb.php' => config_path('oracledb.php'),
        ], 'oracledb-config');
    }

    /**
     * Register the service provider.
     *
     * @returns Jfelder\OrcaleDB\OracleConnection
     */
    public function register()
    {
        if (file_exists(config_path('oracledb.php'))) {
            $this->mergeConfigFrom(config_path('oracledb.php'), 'database.connections');
        } else {
            $this->mergeConfigFrom(__DIR__.'/../../config/oracledb.php', 'database.connections');
        }

        Connection::resolverFor('oracle', function ($connection, $database, $prefix, $config) {
            if (! empty($config['dynamic'])) {
                call_user_func_array($config['dynamic'], [&$config]);
            }

            $connector = new OracleConnector;
            $connection = $connector->connect($config);
            $db = new OracleConnection($connection, $database, $prefix, $config);

            // set oracle session variables
            $sessionParameters = [
                'NLS_TIME_FORMAT' => 'HH24:MI:SS',
                'NLS_DATE_FORMAT' => 'YYYY-MM-DD HH24:MI:SS',
                'NLS_TIMESTAMP_FORMAT' => 'YYYY-MM-DD HH24:MI:SS',
                'NLS_TIMESTAMP_TZ_FORMAT' => 'YYYY-MM-DD HH24:MI:SS TZH:TZM',
                'NLS_NUMERIC_CHARACTERS' => '.,',
                ...($config['sessionParameters'] ?? []),
            ];

            $db->setSessionParameters($sessionParameters);

            return $db;
        });
    }
}
