<?php

namespace App\Application\Audit;

final class Redactor
{
    public const REDACTED = '[REDACTED]';

    public const OMITTED = ['omitted' => 'non_json_or_streamed'];

    private const KEYS = ['password', 'passwd', 'pwd', 'passwordconfirmation', 'currentpassword', 'newpassword', 'token', 'accesstoken', 'refreshtoken', 'idtoken', 'clientsecret', 'secret', 'apikey', 'authorization', 'cookie', 'cookies', 'setcookie', 'tokens', 'passwords', 'secrets', 'apikeys'];

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
                $result[$key] = (in_array($normalized, self::KEYS, true) || preg_match('/(?:passwords?|passwordconfirmation|passwd|tokens?|secrets?|apikeys?|authorization|cookies?)$/', $normalized)) ? self::REDACTED : $this->sanitize($item);
            }

            return $result;
        }
        if (is_string($value)) {
            // A string can itself contain a serialized JSON document.
            $decoded = json_decode($value, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return json_encode($this->sanitize($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $value = preg_replace('/\b(?:set[ _.-]*)?cookies?\s*[=:][^\r\n]*/i', self::REDACTED, $value);
            $value = preg_replace('/\b(?:Bearer|Basic)\s+[a-z0-9+\/_.=~:-]+/i', self::REDACTED, $value);
            $value = preg_replace('/\beyJ[a-z0-9_-]+\.[a-z0-9_-]+\.[a-z0-9_-]+\b/i', self::REDACTED, $value);
            $keys = '(?:current|new)[ _.-]*password|password[ _.-]*confirmation|passwords?|passwd|pwd|(?:access|refresh|id)[ _.-]*token|tokens?|client[ _.-]*secret|secrets?|api[ _.-]*keys?|authorization|(?:set[ _.-]*)?cookies?';

            return preg_replace('/\b(?:'.$keys.')\b["\']?\s*[=:]\s*(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s&,;]+)(?:[ \t]+[^\r\n]*)?/i', self::REDACTED, $value);
        }

        return is_scalar($value) || $value === null ? $value : self::OMITTED;
    }
}
