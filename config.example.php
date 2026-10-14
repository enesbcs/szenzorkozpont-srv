<?php
// SzenzorKozpont — example config.
// Copy to config.php and adjust. On shared hosting write the
// MySQL credentials here (YunoHost my_webapp: db_access.txt).
// For testing the SQLite defaults work without any setup.
return [
    // 'mysql' or 'sqlite'
    'db_driver' => getenv('SZK_DB_DRIVER') ?: 'sqlite',

    // MySQL connection details
    'mysql_host' => getenv('SZK_MYSQL_HOST') ?: 'localhost',
    'mysql_db'   => getenv('SZK_MYSQL_DB') ?: 'szenzorkozpont',
    'mysql_user' => getenv('SZK_MYSQL_USER') ?: 'szenzorkozpont',
    'mysql_pass' => getenv('SZK_MYSQL_PASS') ?: '',

    // SQLite (file in the server data/ dir)
    'sqlite_path' => getenv('SZK_SQLITE_PATH') ?: __DIR__ . '/data/szenzorkozpont.db',

    // Writable data dir (OTA files, temp files)
    'data_dir' => getenv('SZK_DATA_DIR') ?: __DIR__ . '/data',

    // Data retention in days (raw + hourly together)
    'retention_days' => (int)(getenv('SZK_RETENTION_DAYS') ?: 30),

    // Timezone: always UTC (clients send epoch seconds)
    'timezone' => 'UTC',

    // Display timezone for the web UI (storage stays epoch/UTC)
    'display_tz' => 'Europe/Budapest',

    // Web base path: '' = domain root. For sub-URL installs
    // e.g. '/szenzorkozpont'. null = auto-detect.
    'base_path' => null,
];
