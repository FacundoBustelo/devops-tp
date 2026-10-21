<?php

namespace Tests\Unit;

use App\Application\Audit\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function test_nested_lists_key_variants_and_public_values(): void
    {
        $redactor = new Redactor;
        $keys = ['PASSWORD', 'password_confirmation', 'Access-Token', 'refresh token', 'client_secret', 'API.KEY', 'Authorization', 'Cookie', 'Set-Cookie', 'current_password', 'new-password', 'API Keys', 'tokens', 'secrets', 'X-API-Key', 'auth_token', 'dbPassword'];
        $input = ['public' => 'cats', 'list' => [['id' => 'AbC', 'token' => 'private']]];
        foreach ($keys as $key) {
            $input['nested'][$key] = 'private';
        }
        $output = $redactor->sanitize($input);
        self::assertSame('cats', $output['public']);
        self::assertSame('AbC', $output['list'][0]['id']);
        self::assertSame(Redactor::REDACTED, $output['list'][0]['token']);
        foreach ($keys as $key) {
            self::assertSame(Redactor::REDACTED, $output['nested'][$key]);
        }
        self::assertStringNotContainsString('private', json_encode($output));
    }

    public function test_textual_and_serialized_credentials_in_responses(): void
    {
        $redactor = new Redactor;
        foreach (['Bearer private', 'Basic private', 'password=private', 'payload {"password":"private"}', 'current-password: private', 'api-key: private', 'Authorization: Bearer private', 'cookie: session=private; other=private', '{"client_secret":"private","public":1}'] as $text) {
            self::assertStringNotContainsString('private', $redactor->sanitize($text));
        }
        self::assertSame(Redactor::REDACTED, $redactor->sanitize('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature'));
        self::assertSame('Public text with spaces', $redactor->sanitize('Public text with spaces'));
        self::assertSame(['id' => 'AbC', 'count' => 0, 'ok' => true], $redactor->sanitize(['id' => 'AbC', 'count' => 0, 'ok' => true]));
    }
}
