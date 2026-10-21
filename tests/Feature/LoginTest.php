<?php

namespace Tests\Feature;

use App\Application\Audit\Redactor;
use App\Domain\Auth\Authenticator;
use App\Domain\Auth\LoginResult;
use App\Infrastructure\Audit\ApiInteraction;
use App\Infrastructure\Favorites\FavoriteGifModel;
use App\Models\User;
use Database\Seeders\LocalUserSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\Support\OfflineHttp;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use DatabaseMigrations;

    private static string $privateKey = '';

    private static string $publicKey;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, self::$privateKey);
        self::$publicKey = openssl_pkey_get_details($key)['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['passport.private_key' => self::$privateKey, 'passport.public_key' => self::$publicKey, 'cache.default' => 'database']);
        Passport::$keyPath = null;
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test login', 'users');
        Route::get('/api/_test/identity', fn (Request $r) => ['id' => $r->user()->id])->middleware('auth:api');
    }

    public function test_soft_deleted_user_cannot_login_or_use_existing_token_and_seeder_preserves_deletion(): void
    {
        $user = $this->user();
        $token = $this->login()->assertOk()->json('access_token');
        $user->delete();
        $this->login()->assertUnauthorized()->assertExactJson(['message' => 'Credenciales inválidas.']);
        $this->identity($token)->assertUnauthorized();
        $this->seed(LocalUserSeeder::class);
        self::assertSame(1, User::withTrashed()->where('email', $user->email)->count());
        self::assertTrue($user->fresh()->trashed());
    }

    private function user(): User
    {
        return User::factory()->create(['email' => 'user@example.test', 'password' => '  contraseña 密 🔐  ']);
    }

    private function login(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/login', array_replace(['email' => 'user@example.test', 'password' => '  contraseña 密 🔐  '], $extra));
    }

    private function identity(?string $token): TestResponse
    {
        Auth::forgetGuards(); // Each HTTP request resolves its own guard in the real runtime.

        return $this->withHeaders(['Authorization' => $token === null ? '' : 'Bearer '.$token])->getJson('/api/_test/identity');
    }

    private function audit(TestResponse $response, ?int $actor): void
    {
        $row = ApiInteraction::latest('id')->firstOrFail();
        self::assertSame($actor, $row->user_id);
        self::assertSame($response->status(), $row->status);
        self::assertSame($response->headers->get('X-Request-ID'), $row->request_id);
        self::assertTrue(Str::isUuid($row->request_id));
        self::assertEquals(app(Redactor::class)->sanitize($response->json()), $row->response_data);
        self::assertStringNotContainsString('contraseña', json_encode([$row->request_data, $row->response_data], JSON_UNESCAPED_UNICODE));
    }

    public function test_real_token_contract_signature_lifetime_persistence_and_revocation(): void
    {
        $user = $this->user();
        $response = $this->login()->assertOk();
        $token = $response->json('access_token');
        $response->assertExactJson(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 1800, 'user' => ['id' => $user->id]]);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->audit($response, $user->id);
        self::assertSame('[REDACTED]', ApiInteraction::sole()->response_data['access_token']);
        [$header, $payload, $signature] = explode('.', $token);
        $claims = json_decode($this->decode($payload), true);
        self::assertEqualsWithDelta(1800, $claims['exp'] - $claims['iat'], 1);
        self::assertSame(1, openssl_verify($header.'.'.$payload, $this->decode($signature), self::$publicKey, OPENSSL_ALGO_SHA256));
        $record = Token::findOrFail($claims['jti']);
        self::assertSame($user->id, (int) $record->user_id);
        self::assertSame((int) $claims['exp'], $record->expires_at->timestamp);
        self::assertFalse($record->revoked);
        $this->identity($token)->assertOk()->assertExactJson(['id' => $user->id]);
        Http::fake(['*' => Http::response(GiphyFindByIdTest::payload())]);
        $show = function (string $bearer) use ($user): TestResponse {
            Auth::forgetGuards();
            $before = ApiInteraction::count();
            $response = $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->getJson('/api/v1/gifs/AbC123');
            $this->audit($response, $response->status() === 200 ? $user->id : null);
            self::assertSame($before + 1, ApiInteraction::count());
            self::assertSame('AbC123', ApiInteraction::latest('id')->firstOrFail()->request_data['route']['id']);

            return $response;
        };
        $show($token)->assertOk()->assertJsonPath('data.id', 'AbC123');
        Http::assertSentCount(1);

        foreach ([null, 'malformed', $header.'.'.$payload.'.'.$this->encode(str_repeat('x', 256))] as $invalid) {
            $this->identity($invalid)->assertUnauthorized();
        }
        $expired = $claims;
        $expired['iat'] = time() - 3600;
        $expired['nbf'] = time() - 3600;
        $expired['exp'] = time() - 1800;
        $signed = $header.'.'.$this->encode(json_encode($expired));
        openssl_sign($signed, $sig, self::$privateKey, OPENSSL_ALGO_SHA256);
        $expiredToken = $signed.'.'.$this->encode($sig);
        $this->identity($expiredToken)->assertUnauthorized();
        $show($expiredToken)->assertUnauthorized();
        Http::assertSentCount(1);
        $this->identity($token)->assertOk();
        $record->revoke();
        $this->identity($token)->assertUnauthorized();
        $show($token)->assertUnauthorized();
        Http::assertSentCount(1);
    }

    public function test_uniform_credentials_errors_and_spoofing_with_another_real_bearer(): void
    {
        config(['auth.defaults.guard' => 'api']);
        $user = $this->user();
        $other = User::factory()->create();
        $bearer = $other->createToken('other')->accessToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'X-Request-ID' => 'client']);
        foreach ([['email' => 'missing@example.test'], ['password' => 'incorrect'], ['password' => 'contraseña 密 🔐']] as $input) {
            $response = $this->login($input + ['USER_ID' => $other->id])->assertUnauthorized()->assertExactJson(['message' => 'Credenciales inválidas.']);
            $this->audit($response, null);
        }
        $response = $this->login(['USER_ID' => $other->id])->assertOk();
        $this->audit($response, $user->id);
        self::assertSame(4, ApiInteraction::count());
        self::assertSame(4, ApiInteraction::distinct()->count('request_id'));
        self::assertSame(2, Token::count());
    }

    public function test_password_validation_messages_are_readable_and_input_is_redacted_in_audit(): void
    {
        foreach ([[], ['password' => null], ['password' => ''], ['password' => ['private-secret']]] as $input) {
            $message = is_array($input['password'] ?? null)
                ? 'The password field must be a string.'
                : 'The password field is required.';
            $response = $this->postJson('/api/v1/login', ['email' => 'user@example.test'] + $input)
                ->assertUnprocessable()
                ->assertExactJson(['message' => 'Datos inválidos.', 'errors' => ['password' => [$message]]]);
            $this->audit($response, null);
            $row = ApiInteraction::latest('id')->firstOrFail();
            if (array_key_exists('password', $input)) {
                self::assertSame(Redactor::REDACTED, $row->request_data['body']['password']);
            }
            self::assertStringNotContainsString('private-secret', json_encode([$row->request_data, $row->response_data]));
        }
        self::assertSame(0, Token::count());
    }

    public function test_validation_is_counted_before_sixth_attempt_and_audited(): void
    {
        $this->user();
        foreach ([[], ['email' => 'invalid', 'password' => 'x'], ['email' => 'user@example.test', 'password' => []], ['email' => 'user@example.test', 'password' => '']] as $input) {
            $r = $this->postJson('/api/v1/login', $input)->assertUnprocessable();
            $this->audit($r, null);
        }
        $this->login()->assertOk();
        $r = $this->login()->assertStatus(429)->assertHeader('Retry-After');
        $this->audit($r, null);
        self::assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        self::assertSame(6, ApiInteraction::count());
        self::assertSame(1, Token::count());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2']);
        $this->login()->assertOk();
    }

    public function test_proxy_ip_controls_limiting_and_untrusted_headers_do_not(): void
    {
        config(['audit.trusted_proxies' => ['10.1.2.3']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3']);
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(['X-Forwarded-For' => '203.0.113.1'])->postJson('/api/v1/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', [])->assertStatus(429);
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.2'])->postJson('/api/v1/login', [])->assertUnprocessable();
        self::assertSame('203.0.113.2', ApiInteraction::latest('id')->first()->ip);
        config(['audit.trusted_proxies' => []]);
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(['X-Forwarded-For' => '198.51.100.'.$i])->postJson('/api/v1/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', [])->assertStatus(429);
        self::assertSame('10.1.2.3', ApiInteraction::latest('id')->first()->ip);
    }

    public function test_infrastructure_errors_are_sanitized_audited_and_do_not_assign_actor(): void
    {
        $this->user();
        $this->app->bind(Authenticator::class, fn () => new class implements Authenticator
        {
            public function authenticate(string $email, string $password): LoginResult
            {
                throw new \RuntimeException('SQL password=internal-secret');
            }
        });
        $r = $this->login()->assertStatus(500)->assertExactJson(['message' => 'Error del servidor.']);
        $this->audit($r, null);
        self::assertSame(1, ApiInteraction::count());
        self::assertSame(0, Token::count());
    }

    public function test_missing_personal_client_is_a_real_infrastructure_error(): void
    {
        $this->user();
        Passport::client()->newQuery()->delete();
        $r = $this->login()->assertStatus(500)->assertExactJson(['message' => 'Error del servidor.']);
        $this->audit($r, null);
        self::assertSame(0, Token::count());
    }

    public function test_seeder_creates_once_and_preserves_existing_credentials(): void
    {
        $this->seed(LocalUserSeeder::class);
        $user = User::sole();
        self::assertTrue(Hash::check('contraseña-local', $user->password));
        $user->update(['name' => 'Changed', 'password' => 'changed-password']);
        $this->seed(LocalUserSeeder::class);
        self::assertSame(1, User::count());
        self::assertSame('Changed', $user->fresh()->name);
        self::assertTrue(Hash::check('changed-password', $user->fresh()->password));
    }

    public function test_search_with_real_login_token_rejects_revoked_and_expired_tokens_before_provider(): void
    {
        $user = $this->user();
        $token = $this->login()->assertOk()->json('access_token');
        $search = function (string $bearer) {
            Auth::forgetGuards();

            return $this->withHeaders(['Authorization' => 'Bearer '.$bearer])->getJson('/api/v1/gifs?QUERY=cats');
        };
        Http::fake(['*' => Http::response(['data' => [], 'pagination' => ['offset' => 0, 'count' => 0]])]);
        $r = $search($token)->assertOk();
        $this->audit($r, $user->id);
        Http::assertSentCount(1);
        [$header, $payload] = explode('.', $token);
        $claims = json_decode($this->decode($payload), true);
        $expired = $claims;
        $expired['iat'] = time() - 3600;
        $expired['nbf'] = time() - 3600;
        $expired['exp'] = time() - 1800;
        $signed = $header.'.'.$this->encode(json_encode($expired));
        openssl_sign($signed, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);
        Http::fake();
        $r = $search($signed.'.'.$this->encode($signature))->assertUnauthorized();
        $this->audit($r, null);
        Token::findOrFail($claims['jti'])->revoke();
        $r = $search($token)->assertUnauthorized();
        $this->audit($r, null);
        Http::assertNothingSent();
        self::assertSame(4, ApiInteraction::count());
    }

    public function test_all_protected_services_reject_every_invalid_real_token_before_catalog_or_writes(): void
    {
        $user = $this->user();
        $token = $this->login()->assertOk()->json('access_token');
        [$header, $payload] = explode('.', $token);
        $claims = json_decode($this->decode($payload), true);
        $expired = array_replace($claims, ['iat' => time() - 3600, 'nbf' => time() - 3600, 'exp' => time() - 1800]);
        $signed = $header.'.'.$this->encode(json_encode($expired));
        self::assertTrue(openssl_sign($signed, $signature, self::$privateKey, OPENSSL_ALGO_SHA256));
        $expiredToken = $signed.'.'.$this->encode($signature);
        self::assertSame(1, openssl_verify($signed, $signature, self::$publicKey, OPENSSL_ALGO_SHA256));
        $services = [
            ['GET', '/api/v1/gifs?QUERY=cats', [], 'api.v1.gifs.search', 200],
            ['GET', '/api/v1/gifs/AbC123', [], 'api.v1.gifs.show', 200],
            ['POST', '/api/v1/favorites', ['GIF_ID' => 'AbC123', 'ALIAS' => 'favorite', 'USER_ID' => $user->id], 'api.v1.favorites.store', 201],
        ];
        $send = function (array $service, ?string $bearer) use ($user, $token, $expiredToken): TestResponse {
            Auth::forgetGuards();
            $before = ApiInteraction::count();
            $r = $this->withHeaders(['Authorization' => $bearer === null ? '' : 'Bearer '.$bearer, 'X-Request-ID' => 'client-spoof'])
                ->json($service[0], $service[1], $service[2]);
            $this->audit($r, $r->status() === 401 ? null : $user->id);
            self::assertSame($before + 1, ApiInteraction::count());
            $row = ApiInteraction::latest('id')->firstOrFail();
            self::assertSame($service[3], $row->service);
            self::assertSame('127.0.0.1', $row->ip);
            self::assertNotSame('client-spoof', $row->request_id);
            self::assertEquals($service[2], $row->request_data['body']);
            $stored = json_encode([$row->request_data, $row->response_data]);
            foreach ([$token, $expiredToken, 'external-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $stored);
                if ($r->status() === 401) {
                    self::assertStringNotContainsString($secret, $r->getContent());
                }
            }

            return $r;
        };
        Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/search?')
            ? ['data' => [], 'pagination' => ['offset' => 0, 'count' => 0]]
            : GiphyFindByIdTest::payload()));
        foreach ($services as $service) {
            $send($service, $token)->assertStatus($service[4]);
        }
        Http::assertSentCount(3);
        self::assertSame(1, FavoriteGifModel::count());
        OfflineHttp::reset();
        Http::fake();
        foreach ([null, 'malformed', $header.'.'.$payload.'.'.$this->encode(str_repeat('x', 256)), $expiredToken] as $invalid) {
            foreach ($services as $service) {
                $send($service, $invalid)->assertUnauthorized()->assertExactJson(['message' => 'No autenticado.']);
            }
        }
        Token::findOrFail($claims['jti'])->revoke();
        foreach ($services as $service) {
            $send($service, $token)->assertUnauthorized();
        }
        Http::assertNothingSent();
        self::assertSame(1, FavoriteGifModel::count());
        self::assertSame(19, ApiInteraction::count());
        self::assertSame(19, ApiInteraction::distinct()->count('request_id'));
    }

    private function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
