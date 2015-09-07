<?php

return [
    'oracle' => [
        'driver'    => 'oci8',
        'tns'       => env('DB_TNS', ''),
        'host'      => env('DB_HOST', ''),
        'port'      => env('DB_PORT', '1521'),
        'database'  => env('DB_DATABASE', ''),
        'username'  => env('DB_USERNAME', ''),
        'password'  => env('DB_PASSWORD', ''),
        'charset'   => 'WE8ISO8859P1',
        'prefix'    => '',
        'quoting'   => false,
    ],
];
