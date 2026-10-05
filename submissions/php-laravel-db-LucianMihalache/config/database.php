<?php

return [

    // The challenge's database: the SQLite file at SQLITE_PATH, in WAL mode with synchronous=NORMAL (rule 6).
    // Everything else about the database (migrations table, Redis, other drivers) is Laravel's default.
    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('SQLITE_PATH', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => false,   // SQLite's own default; the schema is not ours to change
            'busy_timeout' => 5000,
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
        ],
    ],

];
