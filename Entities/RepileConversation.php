<?php

namespace Modules\Repile\Entities;

use Illuminate\Database\Eloquent\Model;

class RepileConversation extends Model
{
    protected $table = 'repile_conversations';

    protected $fillable = [
        'conversation_id',
        'repile_thread_id',
        'repile_thread_path',
        'last_event',
        'last_delivered_at',
        'last_error',
        'last_error_at',
        'working_since',
        'working_for',
    ];

    public const WORKING_TIMEOUT_MINUTES = 30;

    protected $dates = ['last_delivered_at', 'last_error_at', 'working_since', 'created_at', 'updated_at'];

    public static function forConversation($conversation_id)
    {
        return self::firstOrNew(['conversation_id' => (int) $conversation_id]);
    }

    public function repileUrl()
    {
        $base = rtrim((string) \Option::get('repile.url'), '/');
        if ($base === '' || !$this->repile_thread_id) {
            return '';
        }
        $path = self::safeThreadPath($this->repile_thread_path) ?: '/threads/'.rawurlencode($this->repile_thread_id);

        return $base.$path;
    }

    public static function safeThreadPath($path)
    {
        if (!is_string($path) || !preg_match('#^/(?!/)[^@\\\\\x00-\x20\x7f]*$#', $path)) {
            return null;
        }

        return $path;
    }

    public static function startWorking($conversation_id, $for)
    {
        $record = self::forConversation($conversation_id);
        $record->working_since = now();
        $record->working_for = $for !== null ? mb_substr((string) $for, 0, 191) : null;
        $record->save();
    }

    public static function stopWorking($conversation_id)
    {
        self::where('conversation_id', (int) $conversation_id)
            ->whereNotNull('working_since')
            ->update(['working_since' => null, 'working_for' => null]);
    }

    public function isWorking()
    {
        return $this->working_since !== null
            && $this->working_since->gt(now()->subMinutes(self::WORKING_TIMEOUT_MINUTES));
    }
}
