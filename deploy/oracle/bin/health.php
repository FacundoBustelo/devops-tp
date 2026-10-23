<?php

// Operational probe streamed to the published image; no application changes.
$stage = 'bootstrap';
try {
    require '/var/www/html/vendor/autoload.php';
    $app = require '/var/www/html/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $stage = 'environment';
    if (! $app->environment('production') || config('app.debug') || config('observability.enabled')) {
        throw new RuntimeException('Unsafe environment');
    }
    $stage = 'SQL connection';
    $info = Illuminate\Support\Facades\DB::selectOne('SELECT CURRENT_USER() AS account, 1 AS ready');
    if (str_starts_with($info->account, 'root@')) {
        throw new RuntimeException('Root database account');
    }
    // Verify required tables, app credentials, OAuth client and key pair.
    $stage = 'SQL schema';
    Illuminate\Support\Facades\DB::select('SELECT id FROM users LIMIT 1');
    Illuminate\Support\Facades\DB::select('SELECT id FROM oauth_access_tokens LIMIT 1');
    $stage = 'Passport client';
    app(Laravel\Passport\ClientRepository::class)->personalAccessClient('users');
    $private = storage_path('passport/oauth-private.key');
    $public = storage_path('passport/oauth-public.key');
    $stage = 'Passport permissions';
    if ((fileperms($private) & 0777) !== 0600 || (fileperms($public) & 0777) !== 0600) {
        throw new RuntimeException('Unsafe key permissions');
    }
    $stage = 'Passport key pair';
    $details = openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents($private)));
    // phpseclib may serialize PEM with CRLF; compare canonical public keys.
    $publicDetails = openssl_pkey_get_details(openssl_pkey_get_public(file_get_contents($public)));
    if (! hash_equals($details['key'], $publicDetails['key'])) {
        throw new RuntimeException('Invalid key pair');
    }
    echo "Application, SQL and Passport: OK\n";
} catch (Throwable) {
    fwrite(STDERR, "Health probe failed at {$stage}; inspect configuration and service state.\n");
    exit(1);
}
