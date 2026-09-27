<?php

namespace Modules\Repile\Support;

use App\Conversation;
use App\Thread;
use App\User;
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

    public static function send($event, Conversation $conversation, array $refs = [])
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
        DeliverEvent::dispatch($event, (int) $conversation->id, $refs)->onQueue('default');
    }

    public static function thread(Thread $thread)
    {
        return ['thread_id' => (int) $thread->id];
    }

    public static function payload($event, $conversationId, array $refs)
    {
        $conversation = Conversation::find((int) $conversationId);
        if (!$conversation) {
            return $event === self::DELETED ? ['id' => (int) $conversationId, 'state' => 'deleted'] : null;
        }
        if (!Settings::allowsConversation($conversation)) {
            return null;
        }
        $payload = Payload::withLogins($conversation, Payload::conversation($conversation));
        if (!empty($refs['thread_id'])) {
            $thread = Thread::find((int) $refs['thread_id']);
            if ($thread) {
                $payload['thread'] = Payload::thread($thread);
            }
        }
        if (!empty($refs['mention_thread_id'])) {
            $thread = Thread::find((int) $refs['mention_thread_id']);
            $text = $thread ? self::mentionText($thread) : null;
            if ($text === null) {
                return null;
            }
            $user = $thread->created_by_user;
            $payload['mention'] = [
                'threadId' => (int) $thread->id,
                'text' => Payload::outgoingFor($conversation, $text),
                'user' => $user ? Payload::user($user) : null,
            ];
        }
        if (!empty($refs['requested_by_user_id'])) {
            $user = User::find((int) $refs['requested_by_user_id']);
            $payload['requestedBy'] = $user ? Payload::user($user) : null;
        }

        return $payload;
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
        self::send(self::MENTION, $conversation, ['mention_thread_id' => (int) $thread->id]);
    }
}
