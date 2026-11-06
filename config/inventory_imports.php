<?php

return [
    'max_kilobytes' => (int) env('INVENTORY_IMPORT_MAX_KB', 10240),
    'max_rows' => (int) env('INVENTORY_IMPORT_MAX_ROWS', 10000),
    'chunk_size' => 50,
    'queue_connection' => env('INVENTORY_IMPORT_QUEUE_CONNECTION', 'database'),
    'queue' => 'inventory-imports',
    'retention_days' => (int) env('INVENTORY_IMPORT_RETENTION_DAYS', 7),
];
