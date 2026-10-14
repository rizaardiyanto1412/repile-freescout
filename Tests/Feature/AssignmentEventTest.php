<?php

namespace Modules\Repile\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\Repile\Jobs\DeliverEvent;
use Modules\Repile\Support\Events;
use Tests\TestCase;

class AssignmentEventTest extends TestCase
{
    use DatabaseTransactions;

    private $admin;
    private $agent;
    private $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        \Option::set('repile.url', 'https://repile.test');
        \Option::set('repile.webhook_secret', 'secret');
        \Option::set('repile.api_key', 'test-repile-api-key');
        \Option::set('repile.mailbox_ids', []);

        $this->admin = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
        $this->agent = factory(User::class)->create(['role' => User::ROLE_USER]);
        $mailbox = factory(Mailbox::class)->create();
        $mailbox->users()->sync([$this->admin->id, $this->agent->id]);
        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id' => $mailbox->id,
            'customer_id' => factory(Customer::class)->create()->id,
            'created_by_user_id' => $this->admin->id,
            'status' => Conversation::STATUS_ACTIVE,
            'state' => Conversation::STATE_PUBLISHED,
        ]);
    }

    public function testAssigningAndUnassigningSendsTheAssignedEvent()
    {
        Queue::fake();

        $this->conversation->changeUser($this->agent->id, $this->admin);
        $this->conversation->changeUser(Conversation::USER_UNASSIGNED, $this->admin);

        $this->assertSame(
            [Events::ASSIGNED, Events::ASSIGNED],
            $this->pushedEvents()
        );
    }

    private function pushedEvents()
    {
        return Queue::pushed(DeliverEvent::class)
            ->filter(function ($job) {
                return (int) $job->conversationId === (int) $this->conversation->id;
            })
            ->map(function ($job) {
                return $job->event;
            })
            ->values()
            ->all();
    }
}
