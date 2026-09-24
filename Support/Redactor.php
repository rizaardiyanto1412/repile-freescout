<?php

namespace Modules\Repile\Support;

class Redactor
{
    public const MASK = '[redacted]';

    public const LABELS = [
        'password', 'passwd', 'passphrase', 'passcode', 'pass', 'pwd', 'pw',
        'senha', 'contraseña', 'contrasena', 'kata sandi', 'sandi', 'mot de passe', 'passwort',
        'access token', 'auth token', 'token', 'api[ _-]?key', 'secret key', 'client secret', 'secret',
    ];

    public const KEY_PATTERNS = [
        '/\bsk-[A-Za-z0-9_-]{16,}/',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        '/\bglpat-[A-Za-z0-9_-]{20,}/',
    ];

    public static function scrub($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return $value;
        }
        $labels = implode('|', self::LABELS);
        $value = preg_replace(
            '/(?<![\p{L}\p{N}_])((?:'.$labels.')(?:<[^>]*>|\s)*[:=](?:<[^>]*>|\s|&nbsp;)*)("[^"<]*"|\'[^\'<]*\'|[^\s<]+)/iu',
            '$1'.self::MASK,
            $value
        );
        $value = preg_replace(
            '#(\b[a-z][a-z0-9+.-]*://)[^\s/:@<>"\']+:[^\s/@<>"\']+@#i',
            '$1'.self::MASK.'@',
            $value
        );
        foreach (self::KEY_PATTERNS as $pattern) {
            $value = preg_replace($pattern, self::MASK, $value);
        }

        return $value;
    }
}
