<?php

namespace Modules\Repile\Tests\Support;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\Repile\Support\Bot;
use Tests\TestCase;

abstract class RepileTestCase extends TestCase
{
    use DatabaseTransactions;

    protected const API_KEY = 'test-repile-api-key';

    protected $admin;
    protected $mailbox;
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        \Session::start();
        Queue::fake();
        \Option::$cache = [];

        \Option::set('repile.url', 'https://repile.test');
        \Option::set('repile.webhook_secret', 'secret');
        \Option::set('repile.api_key', self::API_KEY);
        \Option::set('repile.mailbox_ids', []);
        \Option::set('repile.redact_credentials', false);
        \Option::set('repile.exclude_notes', false);
        \Option::set('repile.allow_private_network', false);

        $this->admin = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
        $this->mailbox = factory(Mailbox::class)->create();
        $this->conversation = $this->conversationIn($this->mailbox);
    }

    protected function conversationIn(Mailbox $mailbox)
    {
        $mailbox->users()->syncWithoutDetaching([$this->admin->id]);
        $customer = factory(Customer::class)->create();

        return factory(Conversation::class)->create([
            'mailbox_id' => $mailbox->id,
            'customer_id' => $customer->id,
            'created_by_user_id' => $this->admin->id,
            'status' => Conversation::STATUS_ACTIVE,
            'state' => Conversation::STATE_PUBLISHED,
        ]);
    }

    protected function thread(Conversation $conversation, array $attributes)
    {
        return factory(Thread::class)->create(array_merge([
            'conversation_id' => $conversation->id,
            'type' => Thread::TYPE_CUSTOMER,
            'state' => Thread::STATE_PUBLISHED,
            'created_by_user_id' => null,
            'created_by_customer_id' => $conversation->customer_id,
            'customer_id' => $conversation->customer_id,
            'user_id' => null,
            'to' => 'customer@example.org',
        ], $attributes));
    }

    protected function user($role = User::ROLE_USER)
    {
        return factory(User::class)->create(['role' => $role]);
    }

    protected function bot()
    {
        return Bot::user();
    }

    protected function api($method, $uri, array $data = [])
    {
        return $this->json($method, $uri, $data, ['X-FreeScout-API-Key' => self::API_KEY]);
    }

    protected function assertRefused($response, $message)
    {
        $response->assertStatus(422);
        $this->assertSame($message, $response->json()['message']);
    }

    protected function saveSettings(array $settings)
    {
        return $this->actingAs($this->admin)->post('/app-settings/repile', [
            'settings' => array_merge([
                'repile.url' => 'https://repile.test',
                'repile.webhook_secret' => str_repeat('*', 10),
            ], $settings),
            '_token' => csrf_token(),
        ]);
    }
}
