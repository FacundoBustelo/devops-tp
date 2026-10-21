<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$info = $connection->selectOne('SELECT VERSION() AS version, CURRENT_USER() AS account, DATABASE() AS db');
if (config('database.connections.mysql.host') !== 'mysql' || str_starts_with($info->account, 'root@')) {
    throw new RuntimeException('Expected mysql host and a non-root database account.');
}
foreach (['storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
    $file = __DIR__.'/../'.$directory.'/stage1-write-check';
    if (file_put_contents($file, 'writable') === false) {
        throw new RuntimeException('Runtime directory is not writable: '.$directory);
    }
    unlink($file);
}
echo json_encode($info, JSON_PRETTY_PRINT).PHP_EOL;

$mode = $argv[1] ?? 'check';
if ($mode === 'write') {
    $connection->statement('CREATE TABLE IF NOT EXISTS stage1_verification (id INT PRIMARY KEY, marker VARCHAR(64) NOT NULL)');
    $connection->statement("INSERT INTO stage1_verification (id, marker) VALUES (1, 'stage1-persistence') ON DUPLICATE KEY UPDATE marker = VALUES(marker)");
    echo "Persistence marker written.\n";
} elseif ($mode === 'read') {
    $marker = $connection->selectOne('SELECT marker FROM stage1_verification WHERE id = 1');
    if ($marker?->marker !== 'stage1-persistence') {
        throw new RuntimeException('Persistence marker missing.');
    }
    echo "Persistence marker survived container recreation.\n";
} elseif ($mode === 'cleanup') {
    $connection->statement('DROP TABLE IF EXISTS stage1_verification');
    echo "Verification table removed.\n";
} elseif ($mode !== 'check') {
    throw new InvalidArgumentException('Use check, write, read or cleanup.');
}
