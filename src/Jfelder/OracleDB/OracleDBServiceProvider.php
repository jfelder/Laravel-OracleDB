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
        // merge default config
        $this->mergeConfigFrom(__DIR__.'/../../config/oracledb.php', 'database.connections');

        // override any default configs with user config
        if (file_exists(config_path('oracledb.php'))) {
            $this->mergeConfigFrom(config_path('oracledb.php'), 'database.connections');
        }

        // get only oracle configs to loop thru and extend DB
        $config = $this->app['config']->get('oracledb', []);

        $connection_keys = array_keys($config);

        if (is_array($connection_keys)) {
            foreach ($connection_keys as $key) {
                $this->app['db']->extend($key, function ($config) {
                    $oConnector = new Connectors\OracleConnector();

                    $connection = $oConnector->connect($config);

                    return new OracleConnection($connection, $config['database'], $config['prefix']);
                });
            }
        }
    }
}
