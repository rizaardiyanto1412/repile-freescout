<?php

namespace Modules\Repile\Tests\Feature;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\Repile\Tests\Support\RepileTestCase;

class ApiWritesTest extends RepileTestCase
{
    public function testNotesAreWrittenAsTheBotWhenUserIsOmittedOrTheBot()
    {
        $bot = $this->bot();
        foreach ([[], ['user' => $bot->id], ['user' => (string) $bot->id]] as $extra) {
            $response = $this->api('POST', $this->threadsUri(), array_merge(['type' => 'note', 'text' => 'Checked it'], $extra));
            $response->assertStatus(201);
            $this->assertSame((int) $bot->id, (int) Thread::find($response->json()['id'])->created_by_user_id);
        }
    }

    public function testOtherUsersAreRefusedForNotesAndDrafts()
    {
        $deleted = $this->user();
        $deleted->status = User::STATUS_DELETED;
        $deleted->save();
        $before = Thread::where('conversation_id', $this->conversation->id)->count();

        foreach ([$this->admin->id, $this->user()->id, $deleted->id, 999999] as $id) {
            $this->assertRefused($this->api('POST', $this->threadsUri(), ['type' => 'note', 'text' => 'Hi', 'user' => $id]), 'Repile can only act as the Repile user');
            $this->api('POST', $this->threadsUri(), ['type' => 'message', 'state' => 'draft', 'text' => 'Hi', 'user' => $id])
                ->assertStatus(422);
        }

        $this->assertSame($before, Thread::where('conversation_id', $this->conversation->id)->count());
    }

    public function testAHumansDraftIsNeverDeleted()
    {
        $human = $this->thread($this->conversation, [
            'type' => Thread::TYPE_MESSAGE,
            'state' => Thread::STATE_DRAFT,
            'created_by_user_id' => $this->admin->id,
            'created_by_customer_id' => null,
            'user_id' => $this->admin->id,
            'source_via' => Thread::PERSON_USER,
            'body' => 'My own draft',
        ]);

        $this->api('POST', $this->threadsUri(), ['type' => 'message', 'state' => 'draft', 'text' => 'Try again', 'user' => $this->admin->id])
            ->assertStatus(422);
        $this->api('POST', $this->threadsUri(), ['type' => 'message', 'state' => 'draft', 'text' => 'Try again'])
            ->assertStatus(201);

        $this->assertNotNull(Thread::find($human->id));
        $this->assertSame(2, Thread::where('conversation_id', $this->conversation->id)->where('state', Thread::STATE_DRAFT)->count());
    }

    public function testStatusChangesAreCreditedToTheBotOnly()
    {
        $bot = $this->bot();

        $this->assertRefused($this->api('PUT', $this->conversationUri(), ['status' => 'pending', 'byUser' => $this->admin->id]), 'Repile can only act as the Repile user');
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $this->conversation->fresh()->status);

        $this->api('PUT', $this->conversationUri(), ['status' => 'pending'])->assertStatus(204);
        $this->api('PUT', $this->conversationUri(), ['status' => 'closed', 'byUser' => $bot->id])->assertStatus(204);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $this->conversation->fresh()->status);

        $line = Thread::where('conversation_id', $this->conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)
            ->orderBy('id', 'desc')
            ->first();
        $this->assertSame((int) $bot->id, (int) $line->created_by_user_id);
    }

    public function testAssignToAcceptsOnlyUsersWhoCanAccessTheMailbox()
    {
        $member = $this->user();
        $this->mailbox->users()->syncWithoutDetaching([$member->id]);
        $outsider = $this->user();

        $this->assertRefused($this->api('PUT', $this->conversationUri(), ['assignTo' => $outsider->id]), 'User cannot be assigned in this mailbox');
        $this->assertNull($this->conversation->fresh()->user_id);

        $this->api('PUT', $this->conversationUri(), ['assignTo' => $member->id])->assertStatus(204);
        $this->assertSame((int) $member->id, (int) $this->conversation->fresh()->user_id);

        $this->api('PUT', $this->conversationUri(), ['assignTo' => $this->admin->id])->assertStatus(204);
        $this->assertSame((int) $this->admin->id, (int) $this->conversation->fresh()->user_id);
    }

    private function threadsUri()
    {
        return '/repile/api/conversations/'.$this->conversation->id.'/threads';
    }

    private function conversationUri()
    {
        return '/repile/api/conversations/'.$this->conversation->id;
    }
}
