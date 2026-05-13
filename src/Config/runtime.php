<?php

declare(strict_types=1);

return [
    'APP_ENV' => 'production',
    'APP_URL' => 'https://logs.singularthings.io',

    'LOG_INGEST_SECRET' => 'WalkerPisaLogs-2026-ST',
    'LOG_INGEST_USER_AGENT' => 'WalkerPisa-Bridge-Logs',

    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'logs-devices',
    'DB_USERNAME' => 'deploylogs',
    'DB_PASSWORD' => 'J9B8@+Dj9gPFYRz',

    'STORAGE_BASE_PATH' => dirname(__DIR__, 2) . '/storage',
];