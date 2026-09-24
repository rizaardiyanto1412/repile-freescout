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

    public static function redactsCredentials()
    {
        return (bool) \Option::get('repile.redact_credentials', false, true, false);
    }

    public static function excludesNotes()
    {
        return (bool) \Option::get('repile.exclude_notes', false, true, false);
    }

    public static function allowsPrivateNetwork()
    {
        return (bool) \Option::get('repile.allow_private_network', false, true, false);
    }

    public static function urlProblem($url, $allowPrivate)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return __('Enter the Repile URL as https://your-repile-address.');
        }
        $private = self::hostIsPrivate($host);
        if ($private && !$allowPrivate) {
            return __('This address is on a private or local network. Tick "Repile runs on a private network" if that is really where Repile runs.');
        }
        if ($scheme !== 'https' && !($private && self::isLoopbackName($host))) {
            return __('The Repile URL must start with https:// so tickets are encrypted on the way.');
        }

        return null;
    }

    public static function hostIsPrivate($host)
    {
        $host = strtolower(trim((string) $host, '[]'));
        if (self::isLoopbackName($host)) {
            return true;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
            if (stripos($ip, '::ffff:') === 0 && self::hostIsPrivate(substr($ip, 7))) {
                return true;
            }
        }

        return false;
    }

    private static function isLoopbackName($host)
    {
        return $host === 'localhost' || substr($host, -10) === '.localhost' || $host === '::1'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($host, '127.') === 0);
    }

    public static function mailboxIds()
    {
        $ids = \Option::get('repile.mailbox_ids', [], true, false);
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    public static function allowsMailbox($mailboxId)
    {
        $ids = self::mailboxIds();

        return !$ids || in_array((int) $mailboxId, $ids, true);
    }

    public static function allowsConversation($conversation)
    {
        return $conversation && self::allowsMailbox($conversation->mailbox_id);
    }

    public static function scopeMailboxes($query, $column = 'mailbox_id')
    {
        $ids = self::mailboxIds();

        return $ids ? $query->whereIn($column, $ids) : $query;
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
