<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Support\IsolatedDatabase;
use Tests\Support\OfflineHttp;

// Executed only by the isolated MySQL integration test, never exposed as a route.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

IsolatedDatabase::assertAllowed(DB::getDriverName(), DB::connection()->getDatabaseName(), true);
[$script, $userId, $barrier, $label] = $argv;
Passport::actingAs(User::findOrFail($userId));
OfflineHttp::configure();
Http::fake(function () use ($barrier, $label) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Catalog inside transaction.');
    }
    touch($barrier.'/'.$label);
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout.');
        }
        usleep(10000);
    }

    return Http::response(['data' => ['id' => 'Concurrent', 'title' => '', 'url' => 'https://giphy.com/a', 'images' => ['original' => ['url' => 'https://giphy.com/a.gif']]]]);
});
$request = Request::create('/api/v1/favorites', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['GIF_ID' => 'Concurrent', 'ALIAS' => $label, 'USER_ID' => (int) $userId]));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
$kernel->terminate($request, $response);
