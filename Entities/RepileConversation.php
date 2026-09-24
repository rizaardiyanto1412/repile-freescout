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
    ];

    protected $dates = ['last_delivered_at', 'last_error_at', 'created_at', 'updated_at'];

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
        $path = $this->repile_thread_path ?: '/threads/'.rawurlencode($this->repile_thread_id);

        return $base.$path;
    }
}
