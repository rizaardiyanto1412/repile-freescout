<?php

namespace Modules\Repile\Http\Controllers;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Repile\Entities\RepileConversation;
use Modules\Repile\Support\Bot;
use Modules\Repile\Support\Payload;
use Modules\Repile\Support\Settings;

class ApiController extends Controller
{
    public const PAGE_SIZE = 50;

    public function mailboxes()
    {
        $mailboxes = Settings::scopeMailboxes(Mailbox::query(), 'id')->orderBy('name')->get()->map(function ($mailbox) {
            return [
                'id' => (int) $mailbox->id,
                'name' => (string) $mailbox->name,
                'email' => (string) $mailbox->email,
            ];
        })->values()->all();

        return response()->json(['_embedded' => ['mailboxes' => $mailboxes]]);
    }

    public function conversations(Request $request)
    {
        $query = $this->publishedConversations();
        if ($request->filled('mailboxId')) {
            $query->where('mailbox_id', (int) $request->input('mailboxId'));
        }
        if ($request->filled('status')) {
            $code = Payload::statusCode((string) $request->input('status'));
            if ($code === null) {
                return response()->json(['message' => 'Unknown status'], 422);
            }
            $query->where('status', $code);
        }
        $page = max(1, (int) $request->input('page', 1));
        $total = (clone $query)->count();
        $conversations = $query->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->skip(($page - 1) * self::PAGE_SIZE)
            ->take(self::PAGE_SIZE)
            ->get();

        return response()->json([
            '_embedded' => [
                'conversations' => $conversations->map(function ($conversation) {
                    return Payload::conversation($conversation);
                })->values()->all(),
            ],
            'page' => [
                'size' => self::PAGE_SIZE,
                'totalElements' => $total,
                'totalPages' => (int) ceil($total / self::PAGE_SIZE),
                'number' => $page,
            ],
        ]);
    }

    public function conversation(Request $request, $id)
    {
        $conversation = $this->findConversation($id);
        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }
        $embed = array_map('trim', explode(',', (string) $request->input('_embed', '')));
        $data = in_array('threads', $embed, true)
            ? Payload::conversationWithThreads($conversation)
            : Payload::conversation($conversation);

        return response()->json($data);
    }

    public function statuses(Request $request)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        if (count($ids) > 500) {
            return response()->json(['message' => 'At most 500 ids'], 422);
        }
        $found = $this->publishedConversations()
            ->whereIn('id', $ids)
            ->get(['id', 'status']);
        $drafts = Thread::whereIn('conversation_id', $found->pluck('id')->all())
            ->where('state', Thread::STATE_DRAFT)
            ->where('type', Thread::TYPE_MESSAGE)
            ->pluck('conversation_id')
            ->unique()
            ->all();
        $result = [];
        foreach ($found as $conversation) {
            $result[(string) $conversation->id] = [
                'status' => Payload::statusName($conversation->status),
                'hasDraft' => in_array($conversation->id, $drafts),
            ];
        }

        return response()->json(['statuses' => (object) $result]);
    }

    public function updateConversation(Request $request, $id)
    {
        $conversation = $this->findConversation($id);
        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }
        $user = $this->actingUser($request->input('byUser'));
        if (!$user) {
            return response()->json(['message' => 'Repile can only act as the Repile user'], 422);
        }

        $status = null;
        if ($request->filled('status')) {
            $status = Payload::statusCode((string) $request->input('status'));
            if ($status === null) {
                return response()->json(['message' => 'Unknown status'], 422);
            }
        }
        $assignee = null;
        if ($request->filled('assignTo')) {
            $assignee = User::find((int) $request->input('assignTo'));
            if (!$assignee || $assignee->isDeleted()) {
                return response()->json(['message' => 'Unknown assignTo user'], 422);
            }
            if (!$conversation->mailbox || !$conversation->mailbox->usersAssignable(false)->contains('id', $assignee->id)) {
                return response()->json(['message' => 'User cannot be assigned in this mailbox'], 422);
            }
        }
        if ($status === null && $assignee === null) {
            return response()->json(['message' => 'Nothing to update: pass status or assignTo'], 422);
        }

        if ($status !== null && (int) $conversation->status !== $status) {
            $conversation->changeStatus($status, $user);
        }
        if ($assignee !== null && (int) $conversation->user_id !== (int) $assignee->id) {
            $conversation->changeUser($assignee->id, $user);
        }

        return response('', 204);
    }

    public function createThread(Request $request, $id)
    {
        $conversation = $this->findConversation($id);
        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found'], 404);
        }
        $text = trim((string) $request->input('text', ''));
        if ($text === '') {
            return response()->json(['message' => 'text is required'], 422);
        }
        $user = $this->actingUser($request->input('user'));
        if (!$user) {
            return response()->json(['message' => 'Repile can only act as the Repile user'], 422);
        }
        $type = (string) $request->input('type', 'note');
        if (Bot::isBot($user->id)) {
            $record = RepileConversation::where('conversation_id', $conversation->id)->first();
            if ($type === 'note' && $record && $record->isWorking() && $record->working_for && strpos($text, '@') !== 0) {
                $text = '@'.$record->working_for.' '.$text;
            }
            RepileConversation::stopWorking($conversation->id);
        }
        $body = Payload::htmlFromText($text);

        if ($type === 'note') {
            $conversation->createUserThread($user, $body, ['type' => Thread::TYPE_NOTE]);
            $thread = Thread::where('conversation_id', $conversation->id)
                ->where('type', Thread::TYPE_NOTE)
                ->where('created_by_user_id', $user->id)
                ->orderBy('id', 'desc')
                ->first();

            return response()->json(['id' => $thread ? (int) $thread->id : null], 201);
        }

        if ($type === 'message' && $request->input('state') === 'draft') {
            $thread = $this->replaceDraft($conversation, $user, $body);

            return response()->json(['id' => (int) $thread->id], 201);
        }

        return response()->json(['message' => 'The Repile module only creates notes and draft replies'], 422);
    }

    private function replaceDraft(Conversation $conversation, User $user, $body)
    {
        Thread::where('conversation_id', $conversation->id)
            ->where('state', Thread::STATE_DRAFT)
            ->where('type', Thread::TYPE_MESSAGE)
            ->where('created_by_user_id', $user->id)
            ->whereNull('edited_by_user_id')
            ->get()
            ->each(function ($old) {
                $old->delete();
            });

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id = $conversation->user_id;
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->state = Thread::STATE_DRAFT;
        $thread->status = $conversation->status;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $user->id;
        $thread->body = $body;
        $thread->setTo($conversation->customer_email);
        $thread->save();

        $conversation->addToFolder(Folder::TYPE_DRAFTS);
        $conversation->mailbox->updateFoldersCounters(Folder::TYPE_DRAFTS);

        return $thread;
    }

    private function findConversation($id)
    {
        return $this->publishedConversations()->where('id', (int) $id)->first();
    }

    private function publishedConversations()
    {
        return Settings::scopeMailboxes(Conversation::where('state', Conversation::STATE_PUBLISHED));
    }

    private function actingUser($id)
    {
        $bot = Bot::user();
        if ($id === null || $id === '' || (string) (int) $id === (string) $bot->id) {
            return $bot;
        }

        return null;
    }
}
