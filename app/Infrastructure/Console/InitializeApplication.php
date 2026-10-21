<?php

namespace App\Infrastructure\Console;

use Database\Seeders\LocalUserSeeder;
use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use RuntimeException;
use Throwable;

final class InitializeApplication extends Command
{
    protected $signature = 'app:initialize';

    protected $description = 'Preparar la instalación local sin reemplazar secretos ni datos existentes';

    public function handle(ClientRepository $clients): int
    {
        $stage = 'APP_KEY';

        try {
            $this->info('[init] APP_KEY: conservar o generar si está vacía');
            if (blank(config('app.key'))) {
                $this->executeStep('key:generate', ['--force' => true]);
            }

            $stage = 'migraciones';
            $this->info('[init] Ejecutar migraciones pendientes');
            $this->executeStep('migrate', ['--force' => true]);

            $stage = 'claves Passport';
            $this->info('[init] Preparar claves Passport');
            $private = Passport::keyPath('oauth-private.key');
            $public = Passport::keyPath('oauth-public.key');
            if (file_exists($private) !== file_exists($public)) {
                $this->error('[init] Par Passport incompleto. Restaurar ambas claves del mismo respaldo; no se sobrescribió la clave existente. Ver recuperación en README.');

                return self::FAILURE;
            }
            if (! file_exists($private)) {
                $this->executeStep('passport:keys');
            }
            foreach ([$private, $public] as $path) {
                if (! is_readable($path) || ! chmod($path, 0600)) {
                    throw new RuntimeException('Claves no accesibles.');
                }
            }

            $stage = 'cliente Passport';
            $this->info('[init] Reutilizar cliente personal activo compatible con users');
            // Same compatibility rules as Passport's token issuer (including null provider).
            $exists = Passport::client()->newQuery()->where('revoked', false)
                ->where(function ($query): void {
                    $query->where('provider', 'users');
                    if (config('auth.guards.api.provider') === 'users') {
                        $query->orWhereNull('provider');
                    }
                })->get()->contains(fn ($client): bool => $client->hasGrantType('personal_access'));
            if (! $exists) {
                $clients->createPersonalAccessGrantClient('Prex API personal', 'users');
            }

            $stage = 'usuario local';
            if (app()->environment('local')) {
                $this->info('[init] Crear usuario local únicamente si no existe');
                $this->executeStep('db:seed', ['--class' => LocalUserSeeder::class, '--force' => true]);
            }

            if (blank(config('giphy.api_key'))) {
                $this->warn('[init] Falta GIPHY_API_KEY: login disponible; búsqueda, consulta y favoritos requieren configurarla.');
            }
            $this->info('[init] Instalación preparada.');

            return self::SUCCESS;
        } catch (Throwable) {
            // Exceptions may contain SQL bindings or secrets. Only print the stage.
            $this->error("[init] Falló la etapa: {$stage}. Revisar configuración, conexión y permisos; no se borraron datos ni se reemplazaron secretos.");

            return self::FAILURE;
        }
    }

    private function executeStep(string $command, array $arguments = []): void
    {
        if ($this->callSilent($command, $arguments + ['--no-interaction' => true]) !== self::SUCCESS) {
            throw new RuntimeException('La etapa no terminó correctamente.');
        }
    }
}
