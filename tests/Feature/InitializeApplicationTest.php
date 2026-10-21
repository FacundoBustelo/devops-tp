<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class InitializeApplicationTest extends TestCase
{
    use DatabaseMigrations;

    private string $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = sys_get_temp_dir().'/prex-init-'.bin2hex(random_bytes(8));
        mkdir($this->keys);
        Passport::loadKeysFrom($this->keys);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->keys.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->keys);
        Passport::loadKeysFrom(storage_path());
        parent::tearDown();
    }

    public function test_repeated_initialization_preserves_keys_client_and_deleted_local_user(): void
    {
        $this->app['env'] = 'local';
        $clients = $this->app->make(ClientRepository::class);
        $clients->createPersonalAccessGrantClient('Revoked', 'users')->update(['revoked' => true]);
        $clients->createPersonalAccessGrantClient('Other provider', 'other');

        $this->artisan('app:initialize')->assertSuccessful();
        $private = file_get_contents($this->keys.'/oauth-private.key');
        $public = file_get_contents($this->keys.'/oauth-public.key');
        $key = config('app.key');
        $client = $clients->personalAccessClient('users')->id;
        $user = User::where('email', 'user@example.test')->firstOrFail();
        $user->update(['password' => 'changed-password']);
        $password = $user->password;
        $user->delete();

        $this->artisan('app:initialize')->assertSuccessful();
        $this->assertSame($private, file_get_contents($this->keys.'/oauth-private.key'));
        $this->assertSame($public, file_get_contents($this->keys.'/oauth-public.key'));
        $this->assertSame(0600, fileperms($this->keys.'/oauth-public.key') & 0777);
        $this->assertSame($key, config('app.key'));
        $this->assertSame($client, $clients->personalAccessClient('users')->id);
        $this->assertDatabaseCount('oauth_clients', 3);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertTrue($user->fresh()->trashed());
    }

    public function test_incomplete_keys_fail_without_replacing_the_remaining_key_or_creating_client(): void
    {
        file_put_contents($this->keys.'/oauth-private.key', 'preserve-this-key');
        $this->artisan('app:initialize')
            ->expectsOutput('[init] Par Passport incompleto. Restaurar ambas claves del mismo respaldo; no se sobrescribió la clave existente. Ver recuperación en README.')
            ->assertFailed();
        $this->assertSame('preserve-this-key', file_get_contents($this->keys.'/oauth-private.key'));
        $this->assertFileDoesNotExist($this->keys.'/oauth-public.key');
        $this->assertDatabaseCount('oauth_clients', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_non_local_environment_reuses_compatible_null_provider_without_seeding(): void
    {
        file_put_contents($this->keys.'/oauth-private.key', 'existing-private');
        file_put_contents($this->keys.'/oauth-public.key', 'existing-public');
        $this->app->make(ClientRepository::class)->createPersonalAccessGrantClient('Compatible');
        $this->artisan('app:initialize')->assertSuccessful();
        $this->assertDatabaseCount('oauth_clients', 1);
        $this->assertDatabaseCount('users', 0);
    }
}
