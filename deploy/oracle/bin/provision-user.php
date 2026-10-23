<?php

try {
    require '/var/www/html/vendor/autoload.php';
    $app = require '/var/www/html/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $input = json_decode(base64_decode('@@PAYLOAD@@'), true, 512, JSON_THROW_ON_ERROR);
    if (! $app->environment('production') || App\Models\User::withTrashed()->where('email', $input['email'])->exists()) {
        throw new RuntimeException('Refuse existing user or non-production environment');
    }
    App\Models\User::create([
        'name' => $input['name'],
        'email' => $input['email'],
        'password' => Illuminate\Support\Facades\Hash::make($input['password']),
    ]);
    echo "User created; no existing credentials were changed.\n";
} catch (Throwable) {
    fwrite(STDERR, "User creation failed; verify environment, input, database and duplicate email.\n");
    exit(1);
}
