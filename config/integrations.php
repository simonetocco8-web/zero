<?php

return ['queue_connection' => env('INTEGRATIONS_QUEUE_CONNECTION', 'database'), 'queue' => 'integrations', 'max_body_bytes' => 1048576, 'max_attempts' => 5, 'lease_seconds' => 120];
