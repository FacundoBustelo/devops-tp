<?php

namespace Tests\Support;

final class PassportKeys
{
    private static array $keys = [];

    public static function configure(): void
    {
        // Even actingAs resolves a guard requiring a key. Never use installation keys.
        if (self::$keys === []) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$keys = ['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']];
        }
        config(self::$keys);
    }
}
