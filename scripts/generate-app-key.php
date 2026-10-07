<?php

// Helper esclusivamente locale: non distribuire nel document root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

fwrite(STDOUT, 'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL);
fwrite(STDOUT, 'ZERO_INSTALL_TOKEN='.bin2hex(random_bytes(32)).PHP_EOL);
