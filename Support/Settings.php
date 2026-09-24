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
