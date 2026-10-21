<?php

// Explicit installation probe. Never called by the entrypoint.
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$file = storage_path('login-verification.key');
$mode = $argv[1] ?? '';
if ($mode === 'write') {
    if (file_exists($file)) {
        throw new RuntimeException('Probe already exists; read/cleanup it first.');
    }
    $request = Request::create('/api/v1/login', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.2',
    ], json_encode(['email' => 'user@example.test', 'password' => 'contraseña-local']));
    $kernel = $app->make(Kernel::class);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Login probe failed: '.$response->getStatusCode());
    }
    $data = json_decode($response->getContent(), true);
    $kernel->terminate($request, $response);
    file_put_contents($file, json_encode([
        'token' => $data['access_token'], 'user_id' => $data['user']['id'],
        'private' => hash_file('sha256', storage_path('oauth-private.key')),
        'public' => hash_file('sha256', storage_path('oauth-public.key')),
    ]));
    chmod($file, 0600);
} elseif ($mode === 'read') {
    $data = json_decode(file_get_contents($file), true);
    foreach (['private', 'public'] as $key) {
        if ($data[$key] !== hash_file('sha256', storage_path('oauth-'.$key.'.key'))) {
            throw new RuntimeException('Passport key changed.');
        }
    }
    $request = Request::create('/api/_probe', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$data['token']]);
    $user = Auth::guard('api')->setRequest($request)->user();
    if (! $user || (int) $user->id !== $data['user_id']) {
        throw new RuntimeException('Persisted token rejected.');
    }
} elseif ($mode === 'cleanup') {
    if (is_file($file)) {
        unlink($file);
    }
} else {
    throw new RuntimeException('Use write, read or cleanup.');
}
echo "Login installation probe: $mode OK\n";
