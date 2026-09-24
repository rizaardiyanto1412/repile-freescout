<?php

namespace Modules\Repile\Support;

use App\Conversation;
use App\Thread;
use Modules\Repile\Entities\RepileConversation;
use Modules\Repile\Jobs\DeliverEvent;

class Events
{
    public const CREATED = 'convo.created';
    public const CUSTOMER_REPLY = 'convo.customer.reply.created';
    public const AGENT_REPLY = 'convo.agent.reply.created';
    public const STATUS = 'convo.status';
    public const DELETED = 'convo.deleted';
    public const MENTION = 'repile.mention';
    public const RECHECK = 'repile.recheck';

    public const MENTION_PATTERN = '/(^|[^\w@.])@repile\b[:,]?/i';

    public static function send($event, Conversation $conversation, array $extra = [])
    {
        if (!Settings::isConfigured()) {
            return;
        }
        if ((int) $conversation->state === Conversation::STATE_DRAFT) {
            return;
        }
        if (!Settings::allowsConversation($conversation)) {
            return;
        }
        $payload = array_merge(Payload::conversation($conversation), $extra);
        DeliverEvent::dispatch($event, $payload)->onQueue('default');
    }

    public static function thread(Thread $thread)
    {
        return ['thread' => Payload::thread($thread)];
    }

    public static function mentionText(Thread $thread)
    {
        $text = Payload::text($thread);
        if (!preg_match(self::MENTION_PATTERN, $text)) {
            return null;
        }

        return trim(preg_replace(self::MENTION_PATTERN, '$1', $text));
    }

    public static function noteAdded(Conversation $conversation, Thread $thread)
    {
        if (Bot::isBot($thread->created_by_user_id) || !Settings::allowsConversation($conversation)) {
            return;
        }
        $text = self::mentionText($thread);
        if ($text === null) {
            return;
        }
        $user = $thread->created_by_user;
        if (Settings::isConfigured()) {
            RepileConversation::startWorking($conversation->id, $user ? $user->first_name : null);
        }
        self::send(self::MENTION, $conversation, [
            'mention' => [
                'threadId' => (int) $thread->id,
                'text' => $text,
                'user' => $user ? Payload::user($user) : null,
            ],
        ]);
    }
}
