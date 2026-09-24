<?php

namespace Modules\Repile\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\Repile\Entities\RepileConversation;
use Modules\Repile\Jobs\DeliverEvent;
use Modules\Repile\Support\Events;
use Modules\Repile\Support\Settings;
use Tests\TestCase;

class MailboxFilterTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'test-repile-api-key';

    private $admin;
    private $allowedMailbox;
    private $blockedMailbox;
    private $allowed;
    private $blocked;

    protected function setUp(): void
    {
        parent::setUp();
        \Session::start();

        \Option::set('repile.url', 'https://repile.test');
        \Option::set('repile.webhook_secret', 'secret');
        \Option::set('repile.api_key', self::API_KEY);
        \Option::set('repile.mailbox_ids', []);

        $this->admin = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
        $this->allowedMailbox = factory(Mailbox::class)->create(['name' => 'Allowed']);
        $this->blockedMailbox = factory(Mailbox::class)->create(['name' => 'Blocked']);
        $this->allowed = $this->conversationIn($this->allowedMailbox);
        $this->blocked = $this->conversationIn($this->blockedMailbox);
    }

    public function testMailboxIdsDefaultsToAllAndIgnoresJunk()
    {
        $this->assertSame([], Settings::mailboxIds());
        $this->assertTrue(Settings::allowsMailbox($this->blockedMailbox->id));

        \Option::set('repile.mailbox_ids', ['0', '', (string) $this->allowedMailbox->id, $this->allowedMailbox->id]);

        $this->assertSame([(int) $this->allowedMailbox->id], Settings::mailboxIds());
        $this->assertTrue(Settings::allowsMailbox($this->allowedMailbox->id));
        $this->assertFalse(Settings::allowsMailbox($this->blockedMailbox->id));
    }

    public function testMailboxesListsOnlyAllowedMailboxes()
    {
        $ids = $this->mailboxIdsFromApi();
        $this->assertContains((int) $this->allowedMailbox->id, $ids);
        $this->assertContains((int) $this->blockedMailbox->id, $ids);

        $this->allowOnly($this->allowedMailbox);

        $this->assertSame([(int) $this->allowedMailbox->id], $this->mailboxIdsFromApi());
    }

    public function testConversationsListsOnlyAllowedMailboxes()
    {
        $ids = $this->conversationIdsFromApi();
        $this->assertContains((int) $this->allowed->id, $ids);
        $this->assertContains((int) $this->blocked->id, $ids);

        $this->allowOnly($this->allowedMailbox);

        $ids = $this->conversationIdsFromApi();
        $this->assertContains((int) $this->allowed->id, $ids);
        $this->assertNotContains((int) $this->blocked->id, $ids);

        $response = $this->api('GET', '/repile/api/conversations?mailboxId='.$this->blockedMailbox->id);
        $response->assertStatus(200);
        $this->assertSame([], $response->json()['_embedded']['conversations']);
        $this->assertSame(0, $response->json()['page']['totalElements']);
    }

    public function testReadingAConversation()
    {
        $this->api('GET', '/repile/api/conversations/'.$this->blocked->id)->assertStatus(200);

        $this->allowOnly($this->allowedMailbox);

        $this->api('GET', '/repile/api/conversations/'.$this->allowed->id.'?_embed=threads')->assertStatus(200);
        $this->api('GET', '/repile/api/conversations/'.$this->blocked->id)->assertStatus(404);
        $this->api('GET', '/repile/api/conversations/'.$this->blocked->id.'?_embed=threads')->assertStatus(404);
    }

    public function testUpdatingAConversation()
    {
        $this->api('PUT', '/repile/api/conversations/'.$this->blocked->id, ['status' => 'pending'])->assertStatus(204);
        $this->assertSame(Conversation::STATUS_PENDING, (int) $this->blocked->fresh()->status);

        $this->allowOnly($this->allowedMailbox);

        $this->api('PUT', '/repile/api/conversations/'.$this->blocked->id, ['status' => 'closed'])->assertStatus(404);
        $this->assertSame(Conversation::STATUS_PENDING, (int) $this->blocked->fresh()->status);

        $this->api('PUT', '/repile/api/conversations/'.$this->allowed->id, ['status' => 'closed'])->assertStatus(204);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $this->allowed->fresh()->status);
    }

    public function testAddingThreads()
    {
        $this->api('POST', '/repile/api/conversations/'.$this->blocked->id.'/threads', ['type' => 'note', 'text' => 'Open'])
            ->assertStatus(201);

        $this->allowOnly($this->allowedMailbox);
        $before = Thread::where('conversation_id', $this->blocked->id)->count();

        $this->api('POST', '/repile/api/conversations/'.$this->blocked->id.'/threads', ['type' => 'note', 'text' => 'Hidden'])
            ->assertStatus(404);
        $this->api('POST', '/repile/api/conversations/'.$this->blocked->id.'/threads', ['type' => 'message', 'state' => 'draft', 'text' => 'Hidden'])
            ->assertStatus(404);
        $this->assertSame($before, Thread::where('conversation_id', $this->blocked->id)->count());

        $this->api('POST', '/repile/api/conversations/'.$this->allowed->id.'/threads', ['type' => 'note', 'text' => 'Allowed'])
            ->assertStatus(201);
    }

    public function testStatusesLeavesOutBlockedMailboxes()
    {
        $ids = [$this->allowed->id, $this->blocked->id];
        $this->assertEqualsCanonicalizing(
            [(int) $this->allowed->id, (int) $this->blocked->id],
            array_keys($this->api('POST', '/repile/api/statuses', ['ids' => $ids])->json()['statuses'])
        );

        $this->allowOnly($this->allowedMailbox);

        $this->assertSame(
            [(int) $this->allowed->id],
            array_keys($this->api('POST', '/repile/api/statuses', ['ids' => $ids])->json()['statuses'])
        );
    }

    public function testEventsAreOnlySentForAllowedMailboxes()
    {
        Queue::fake();
        Events::send(Events::STATUS, $this->blocked);
        $this->assertSame([(int) $this->blocked->id], $this->pushedConversationIds());

        Queue::fake();
        $this->allowOnly($this->allowedMailbox);
        Events::send(Events::STATUS, $this->blocked);
        Events::send(Events::DELETED, $this->blocked);
        Events::send(Events::STATUS, $this->allowed);

        $this->assertSame([(int) $this->allowed->id], $this->pushedConversationIds());
    }

    public function testMentionsAreIgnoredInBlockedMailboxes()
    {
        $this->allowOnly($this->allowedMailbox);
        Queue::fake();

        Events::noteAdded($this->blocked, $this->mentionNote($this->blocked));
        $this->assertSame([], $this->pushedConversationIds());
        $this->assertNull(RepileConversation::where('conversation_id', $this->blocked->id)->first());

        Events::noteAdded($this->allowed, $this->mentionNote($this->allowed));
        $this->assertSame([(int) $this->allowed->id], $this->pushedConversationIds());
    }

    public function testPanelAndRecheckAreHiddenInBlockedMailboxes()
    {
        $this->assertStringContainsString('repile-recheck', $this->actionButtons($this->blocked));
        $this->assertStringContainsString('repile-card', $this->sidebar($this->blocked));

        $this->allowOnly($this->allowedMailbox);
        Queue::fake();

        $this->assertSame('', $this->actionButtons($this->blocked));
        $this->assertSame('', $this->sidebar($this->blocked));
        $this->assertStringContainsString('repile-recheck', $this->actionButtons($this->allowed));
        $this->assertStringContainsString('repile-card', $this->sidebar($this->allowed));

        $this->actingAs($this->admin)
            ->post('/repile/conversations/'.$this->blocked->id.'/recheck', ['_token' => csrf_token()])
            ->assertStatus(404);
        $this->actingAs($this->admin)
            ->get('/repile/conversations/'.$this->blocked->id.'/state')
            ->assertStatus(404);
        $this->assertSame([], $this->pushedConversationIds());

        $this->actingAs($this->admin)
            ->post('/repile/conversations/'.$this->allowed->id.'/recheck', ['_token' => csrf_token()])
            ->assertStatus(200);
        $this->assertSame([(int) $this->allowed->id], $this->pushedConversationIds());
    }

    public function testSavingSettingsStoresTickedMailboxesAndEmptyMeansAll()
    {
        $this->saveSettings(['repile.mailbox_ids' => [(string) $this->allowedMailbox->id]]);
        $this->assertSame([(int) $this->allowedMailbox->id], Settings::mailboxIds());

        $page = $this->actingAs($this->admin)->get('/app-settings/repile');
        $page->assertStatus(200);
        $this->assertMatchesRegularExpression(
            '/value="'.$this->allowedMailbox->id.'"\s+checked/',
            $page->getContent()
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="'.$this->blockedMailbox->id.'"\s+checked/',
            $page->getContent()
        );

        $this->saveSettings([]);
        $this->assertSame([], Settings::mailboxIds());
        $this->assertTrue(Settings::allowsMailbox($this->blockedMailbox->id));
    }

    private function conversationIn(Mailbox $mailbox)
    {
        $mailbox->users()->sync([$this->admin->id]);
        $customer = factory(Customer::class)->create();

        return factory(Conversation::class)->create([
            'mailbox_id' => $mailbox->id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $this->admin->id,
            'status' => Conversation::STATUS_ACTIVE,
            'state' => Conversation::STATE_PUBLISHED,
        ]);
    }

    private function mentionNote(Conversation $conversation)
    {
        return factory(Thread::class)->create([
            'conversation_id' => $conversation->id,
            'type' => Thread::TYPE_NOTE,
            'body' => '<p>@Repile is this plugin broken?</p>',
            'created_by_user_id' => $this->admin->id,
            'user_id' => $this->admin->id,
        ]);
    }

    private function allowOnly(Mailbox $mailbox)
    {
        \Option::set('repile.mailbox_ids', [(int) $mailbox->id]);
    }

    private function api($method, $uri, array $data = [])
    {
        return $this->json($method, $uri, $data, ['X-FreeScout-API-Key' => self::API_KEY]);
    }

    private function mailboxIdsFromApi()
    {
        $response = $this->api('GET', '/repile/api/mailboxes');
        $response->assertStatus(200);

        return array_map(function ($mailbox) {
            return $mailbox['id'];
        }, $response->json()['_embedded']['mailboxes']);
    }

    private function conversationIdsFromApi()
    {
        $response = $this->api('GET', '/repile/api/conversations');
        $response->assertStatus(200);

        return array_map(function ($conversation) {
            return $conversation['id'];
        }, $response->json()['_embedded']['conversations']);
    }

    private function pushedConversationIds()
    {
        return Queue::pushed(DeliverEvent::class)->map(function ($job) {
            return (int) $job->payload['id'];
        })->values()->all();
    }

    private function actionButtons(Conversation $conversation)
    {
        ob_start();
        \Eventy::action('conversation.append_action_buttons', $conversation, $conversation->mailbox);

        return trim(ob_get_clean());
    }

    private function sidebar(Conversation $conversation)
    {
        $this->actingAs($this->admin);
        ob_start();
        \Eventy::action('conversation.after_prev_convs', $conversation->customer, $conversation, $conversation->mailbox);

        return trim(ob_get_clean());
    }

    private function saveSettings(array $settings)
    {
        $this->actingAs($this->admin)
            ->post('/app-settings/repile', [
                'settings' => array_merge([
                    'repile.url' => 'https://repile.test',
                    'repile.webhook_secret' => str_repeat('*', 10),
                ], $settings),
                '_token' => csrf_token(),
            ])
            ->assertStatus(302);
    }
}
