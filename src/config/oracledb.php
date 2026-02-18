<?php

return [
    'oracle' => [
        'driver' => 'oracle',
        'tns' => env('DB_TNS', ''),
        'host' => env('DB_HOST', ''),
        'port' => env('DB_PORT', '1521'),
        'service_name' => env('DB_SERVICE_NAME', ''),
        'database' => env('DB_DATABASE', ''),
        'username' => env('DB_USERNAME', ''),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'WE8ISO8859P1'),
        'prefix' => '',
        'quoting' => false,
        'load_balance' => env('DB_LOAD_BALANCE', ''),
        'failover' => env('DB_FAILOVER', ''),
        'failover_mode' => [
            'type' => env('DB_FM_TYPE', 'SELECT'),
            'method' => env('DB_FM_METHOD', 'BASIC'),
            'retries' => env('DB_FM_RETRIES', 20),
            'delay' => env('DB_FM_DELAY', 15),
        ],
        'session_parameters' => [
            'NLS_TIME_FORMAT' => env('NLS_TIME_FORMAT', 'HH24:MI:SS'),
            'NLS_DATE_FORMAT' => env('NLS_DATE_FORMAT', 'YYYY-MM-DD HH24:MI:SS'),
            'NLS_TIMESTAMP_FORMAT' => env('NLS_TIMESTAMP_FORMAT', 'YYYY-MM-DD HH24:MI:SS'),
            'NLS_TIMESTAMP_TZ_FORMAT' => env('NLS_TIMESTAMP_TZ_FORMAT', 'YYYY-MM-DD HH24:MI:SS TZH:TZM'),
            'NLS_NUMERIC_CHARACTERS' => env('NLS_NUMERIC_CHARACTERS', '.,'),
        ],
    ],
];
