<?php

namespace Jfelder\OracleDB;

use Illuminate\Support\ServiceProvider;

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
        $this->publishes(
            [
                __DIR__.'/../../config/oracledb.php' => config_path('oracledb.php'),
            ]
        );
    }

    /**
     * Register the service provider.
     *
     * @returns Jfelder\OrcaleDB\OracleConnection
     */
    public function register()
    {
        if (file_exists(config_path('oracledb.php'))) {
            // merge config with other connections
            $this->mergeConfigFrom(config_path('oracledb.php'), 'database.connections');

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
}
