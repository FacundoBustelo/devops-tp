<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

final class LocalUserSeeder extends Seeder
{
    public function run(): void
    {
        User::withTrashed()->firstOrCreate(['email' => 'user@example.test'], [
            'name' => 'Usuario local', 'password' => 'contraseña-local',
        ]);
    }
}
