<?php

namespace Jfelder\OracleDB;

use Illuminate\Support\ServiceProvider;

class OracleDBServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // this  for conig
        $this->publishes([
            __DIR__.'/config/oracledb.php' => config_path('oracledb.php'),
        ]);
    }
	/**
	 * Register the service provider.
     *
     * @throws \ErrorException
     */
	public function register()
	{
        // merge config with other connections
        $this->mergeConfigFrom(config_path('oracledb.php'), 'database.connections');

        // get only oracle configs to loop thru and extend DB
        $config = $this->app['config']->get('oracledb', []);

        $connection_keys = array_keys($config);

        if (is_array($connection_keys))
        {
            foreach ($connection_keys as $key)
            {
                $this->app['db']->extend($key, function($config)
                {
                    $oConnector = new Connectors\OracleConnector();

                    $connection = $oConnector->connect($config);

                    return new OracleConnection($connection, $config["database"], $config["prefix"]);
                });
            }
        }
        else
        {
            throw new \ErrorException('Configuration File is corrupt or not present.');
        }
	}

}
