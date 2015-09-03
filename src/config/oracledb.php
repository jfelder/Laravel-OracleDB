<?php

return [
    'oracle' => [
        'driver'    => env('DB_DRIVER', 'oracle'),
        'tns'       => env('DB_TNS', ''),
        'host'      => env('DB_HOST', ''),
        'port'      => env('DB_PORT', '1521'),
        'database'  => env('DB_DATABASE', ''),
        'username'  => env('DB_USERNAME', ''),
        'password'  => env('DB_PASSWORD', ''),
        'charset'   => env('DB_CHARSET', 'WE8ISO8859P1'),
        'prefix'    => env('DB_PREFIX', ''),
		'quoting'   => env('DB_QUOTING', false),
	],
];
