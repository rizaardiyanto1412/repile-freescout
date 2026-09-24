<?php

namespace Modules\Repile\Support;

class Settings
{
    public const WEBHOOK_PATH = '/api/v1/plugins/freescout/http/webhook';

    public static function repileUrl()
    {
        return rtrim(trim((string) \Option::get('repile.url', '', true, false)), '/');
    }

    public static function webhookSecret()
    {
        return trim((string) \Option::get('repile.webhook_secret', '', true, false));
    }

    public static function webhookUrl()
    {
        $base = self::repileUrl();

        return $base === '' ? '' : $base.self::WEBHOOK_PATH;
    }

    public static function isConfigured()
    {
        return self::repileUrl() !== '' && self::webhookSecret() !== '';
    }

    public static function apiKey()
    {
        $key = (string) \Option::get('repile.api_key');
        if ($key === '') {
            $key = self::regenerateApiKey();
        }

        return $key;
    }

    public static function regenerateApiKey()
    {
        $key = bin2hex(random_bytes(24));
        \Option::set('repile.api_key', $key);

        return $key;
    }

    public static function apiBaseUrl()
    {
        return rtrim(url(\Helper::getSubdirectory().'/repile/api'), '/');
    }
}
