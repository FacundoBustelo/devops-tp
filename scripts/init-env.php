<?php

// Explicit local setup only. Never called by the container entrypoint.
$path = __DIR__.'/../.env';
if (file_exists($path)) {
    fwrite(STDERR, ".env already exists; refusing to replace local credentials.\n");
    exit(1);
}
$contents = file_get_contents(__DIR__.'/../.env.example');
foreach (['DB_PASSWORD', 'MYSQL_ROOT_PASSWORD'] as $name) {
    $contents = preg_replace('/^'.$name.'=$/m', $name.'='.bin2hex(random_bytes(32)), $contents);
}
$contents = preg_replace('/^APP_KEY=$/m', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $contents);
file_put_contents($path, $contents);
chmod($path, 0600);
echo "Created .env with independent local MySQL credentials; APP_KEY generated outside the image.\n";
