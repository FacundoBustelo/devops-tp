<?php

use App\Infrastructure\Audit\ApiInteraction;
use Database\Seeders\LocalUserSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Runtime verification helper, explicitly mounted by the disposable test override only.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
switch ($argv[1] ?? '') {
    case 'identity':
        $response = DB::selectOne('SELECT COUNT(*) AS n FROM api_interactions');
        echo json_encode(['audits' => $response->n, 'passport' => hash_file('sha256', storage_path('passport/oauth-public.key'))]);
        break;
    case 'audit':
        $row = ApiInteraction::where('request_id', $argv[2])->sole();
        echo json_encode(['request_id' => $row->request_id, 'status' => $row->status]);
        break;
    case 'seed':
        $app->make(Kernel::class)->call('db:seed', ['--class' => LocalUserSeeder::class, '--force' => true]);
        break;
    case 'break':
        chmod(dirname(config('observability.database')), 0000);
        break;
    case 'restore':
        chmod(dirname(config('observability.database')), 0700);
        break;
    case 'lock':
        $pdo = new PDO('sqlite:'.config('observability.database'));
        $pdo->exec('BEGIN IMMEDIATE');
        echo "locked\n";
        flush();
        sleep(5);
        $pdo->exec('ROLLBACK');
        break;
    default:
        exit(2);
}
