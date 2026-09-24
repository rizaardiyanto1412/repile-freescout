<?php

namespace Modules\Repile\Support;

use App\Conversation;
use App\Thread;

class Payload
{
    public const STATUSES = [
        Conversation::STATUS_ACTIVE => 'active',
        Conversation::STATUS_PENDING => 'pending',
        Conversation::STATUS_CLOSED => 'closed',
        Conversation::STATUS_SPAM => 'spam',
    ];

    public const CONVERSATION_TYPES = [
        Conversation::TYPE_EMAIL => 'email',
        Conversation::TYPE_PHONE => 'phone',
        Conversation::TYPE_CHAT => 'chat',
    ];

    public static function statusName($status)
    {
        return self::STATUSES[(int) $status] ?? 'active';
    }

    public static function statusCode($name)
    {
        $code = array_search($name, self::STATUSES, true);

        return $code === false ? null : (int) $code;
    }

    public static function conversation(Conversation $conversation)
    {
        $customer = $conversation->customer;
        $assignee = $conversation->user;

        return [
            'id' => (int) $conversation->id,
            'number' => (int) $conversation->number,
            'threadsCount' => (int) $conversation->threads_count,
            'type' => self::CONVERSATION_TYPES[(int) $conversation->type] ?? 'email',
            'status' => self::statusName($conversation->status),
            'state' => (int) $conversation->state === Conversation::STATE_DELETED ? 'deleted' : 'published',
            'subject' => (string) $conversation->subject,
            'preview' => self::outgoing((string) $conversation->preview),
            'mailboxId' => (int) $conversation->mailbox_id,
            'assignee' => $assignee ? self::user($assignee) : null,
            'customer' => $customer ? self::customer($customer, $conversation->customer_email) : null,
            'createdAt' => self::time($conversation->created_at),
            'updatedAt' => self::time($conversation->updated_at),
            'url' => $conversation->url(),
        ];
    }

    public static function conversationWithThreads(Conversation $conversation)
    {
        $data = self::conversation($conversation);
        $threads = Thread::where('conversation_id', $conversation->id)
            ->whereIn('state', [Thread::STATE_PUBLISHED, Thread::STATE_DRAFT])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();
        if (Settings::excludesNotes()) {
            $threads = $threads->filter(function ($thread) {
                return self::sharesNote($thread);
            });
        }
        $data['_embedded'] = [
            'threads' => $threads->map(function ($thread) {
                return self::thread($thread);
            })->values()->all(),
        ];

        return $data;
    }

    public static function thread(Thread $thread)
    {
        $created_by = null;
        if ($thread->created_by_user_id) {
            $user = $thread->created_by_user;
            $created_by = $user ? self::user($user) : ['id' => (int) $thread->created_by_user_id, 'type' => 'user'];
        } elseif ($thread->created_by_customer_id) {
            $customer = $thread->created_by_customer;
            $created_by = $customer ? self::customer($customer, null) : ['id' => (int) $thread->created_by_customer_id, 'type' => 'customer'];
        }

        return [
            'id' => (int) $thread->id,
            'type' => Thread::$types[(int) $thread->type] ?? 'message',
            'state' => (int) $thread->state === Thread::STATE_DRAFT ? 'draft' : 'published',
            'status' => self::statusName($thread->status),
            'createdAt' => self::time($thread->created_at),
            'createdBy' => $created_by,
            'body' => self::outgoing((string) $thread->body),
            'text' => self::outgoing(self::text($thread)),
        ];
    }

    public static function sharesNote(Thread $thread)
    {
        if ((int) $thread->type !== Thread::TYPE_NOTE) {
            return true;
        }

        return Bot::isBot($thread->created_by_user_id) || Events::mentionText($thread) !== null;
    }

    public static function outgoing($value)
    {
        return Settings::redactsCredentials() ? Redactor::scrub($value) : $value;
    }

    public static function user($user)
    {
        return [
            'id' => (int) $user->id,
            'type' => 'user',
            'firstName' => (string) $user->first_name,
            'lastName' => (string) $user->last_name,
            'email' => (string) $user->email,
        ];
    }

    public static function customer($customer, $email)
    {
        $main = $email ?: $customer->getMainEmail();

        return [
            'id' => (int) $customer->id,
            'type' => 'customer',
            'firstName' => (string) $customer->first_name,
            'lastName' => (string) $customer->last_name,
            'email' => (string) $main,
        ];
    }

    public static function text(Thread $thread)
    {
        $body = (string) $thread->body;
        if ($body === '') {
            return '';
        }
        $text = \Helper::htmlToText($body);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function htmlFromText($text)
    {
        $text = (string) $text;
        if (preg_match('#</?(p|br|div|a|ul|ol|li|strong|em|b|i|pre|code|blockquote|h[1-6]|table)\b[^>]*>#i', $text)) {
            return self::purify($text);
        }

        return nl2br(e($text));
    }

    public static function purify($html)
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,ul,ol,li,code,pre,blockquote,a[href]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);

        return (new \HTMLPurifier($config))->purify((string) $html);
    }

    private static function time($value)
    {
        return $value ? $value->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') : null;
    }
}
