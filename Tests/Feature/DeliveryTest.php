<?php

namespace Modules\Repile\Tests\Feature;

use App\Conversation;
use App\Thread;
use Illuminate\Support\Facades\Queue;
use Modules\Repile\Entities\RepileConversation;
use Modules\Repile\Jobs\DeliverEvent;
use Modules\Repile\Support\Events;
use Modules\Repile\Tests\Support\RepileTestCase;

class DeliveryTest extends RepileTestCase
{
    private static $server;
    private static $port;
    private static $dir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$dir = sys_get_temp_dir().'/repile-fake-'.bin2hex(random_bytes(4));
        mkdir(self::$dir);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/../Support/fake-repile.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['REPILE_FAKE_DIR' => self::$dir]
        );
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', self::$port)) {
                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        array_map('unlink', glob(self::$dir.'/*'));
        rmdir(self::$dir);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Option::set('repile.url', 'http://127.0.0.1:'.self::$port);
        \Option::set('repile.allow_private_network', true);
        @unlink(self::$dir.'/requests.jsonl');
        $this->respond(200, '{"ok":true}');
    }

    public function testEventsCarryReplayProtectionHeaders()
    {
        $this->deliver(Events::STATUS);
        $this->deliver(Events::STATUS);
        [$first, $second] = $this->requests();

        $body = $first['body'];
        $headers = $first['headers'];
        $this->assertSame('/api/v1/plugins/freescout/http/webhook', $first['uri']);
        $this->assertSame(base64_encode(hash_hmac('sha1', $body, 'secret', true)), $headers['x-freescout-signature']);
        $this->assertSame('convo.status', $headers['x-freescout-event']);
        $this->assertEqualsWithDelta(time(), (int) $headers['x-repile-timestamp'], 5);
        $this->assertSame(
            hash_hmac('sha256', $headers['x-repile-timestamp'].'.'.$body, 'secret'),
            $headers['x-repile-signature']
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $headers['x-repile-delivery']);
        $this->assertNotSame($headers['x-repile-delivery'], $second['headers']['x-repile-delivery']);
    }

    public function testQueuedJobsHoldNoTicketText()
    {
        \Option::set('repile.url', 'https://repile.test');
        $thread = $this->thread($this->conversation, ['body' => '<p>Password: hunter2 for my-site.example</p>']);
        $note = $this->thread($this->conversation, [
            'type' => Thread::TYPE_NOTE,
            'body' => '<p>@Repile the login is hunter2</p>',
            'created_by_user_id' => $this->admin->id,
            'created_by_customer_id' => null,
            'user_id' => $this->admin->id,
        ]);

        Events::send(Events::CUSTOMER_REPLY, $this->conversation, Events::thread($thread));
        Events::noteAdded($this->conversation, $note);

        $jobs = Queue::pushed(DeliverEvent::class);
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $stored = serialize($job);
            $this->assertStringNotContainsString('hunter2', $stored);
            $this->assertStringNotContainsString('my-site.example', $stored);
            $this->assertStringNotContainsString((string) $this->conversation->subject, $stored);
        }
    }

    public function testRetriesSendTheCurrentThread()
    {
        $thread = $this->thread($this->conversation, ['body' => 'First version']);
        $job = new DeliverEvent(Events::CUSTOMER_REPLY, $this->conversation->id, Events::thread($thread));
        $thread->body = 'Edited version';
        $thread->save();

        $job->handle();

        $sent = json_decode($this->requests()[0]['body'], true);
        $this->assertSame('Edited version', $sent['thread']['body']);
        $this->assertSame((int) $this->conversation->id, $sent['id']);
        $this->assertArrayHasKey('customer', $sent);
    }

    public function testConversationsDeletedForeverSendOnlyTheirId()
    {
        $id = (int) $this->conversation->id;
        $deleted = new DeliverEvent(Events::DELETED, $id);
        $reply = new DeliverEvent(Events::CUSTOMER_REPLY, $id);
        Thread::where('conversation_id', $id)->delete();
        Conversation::where('id', $id)->delete();

        $deleted->handle();
        $reply->handle();

        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $this->assertSame(['id' => $id, 'state' => 'deleted'], json_decode($requests[0]['body'], true));
    }

    public function testUnsafeThreadPathsFallBackToTheThreadId()
    {
        foreach (['@evil.example/x', '//evil.example/x', '\\evil.example', '/ok@evil.example', "/a\nb", 'threads/x'] as $path) {
            $this->respond(200, json_encode(['threadId' => 'abc 1', 'threadPath' => $path]));
            $this->deliver(Events::STATUS);
            $record = RepileConversation::where('conversation_id', $this->conversation->id)->first();
            $this->assertNull($record->repile_thread_path, $path);
            $this->assertSame('http://127.0.0.1:'.self::$port.'/threads/abc%201', $record->repileUrl());

            $record->repile_thread_path = $path;
            $this->assertSame('http://127.0.0.1:'.self::$port.'/threads/abc%201', $record->repileUrl(), $path);
        }

        $this->respond(200, json_encode(['threadId' => 'abc', 'threadPath' => '/threads/abc123']));
        $this->deliver(Events::STATUS);
        $record = RepileConversation::where('conversation_id', $this->conversation->id)->first();
        $this->assertSame('http://127.0.0.1:'.self::$port.'/threads/abc123', $record->repileUrl());
    }

    public function testErrorsKeepOnlyTheStatusAndRepilesErrorString()
    {
        $this->respond(502, '<html><body>Bad gateway at internal-proxy.corp:8080</body></html>', 'text/html');
        $this->deliver(Events::STATUS);
        $this->assertSame('Repile answered 502', $this->record()->last_error);

        $this->respond(401, json_encode(['error' => '<b>Invalid</b> signature']));
        $this->deliver(Events::STATUS);
        $this->assertSame('Repile answered 401: Invalid signature', $this->record()->last_error);

        $this->respond(500, '<html>internal secret</html>', 'text/html');
        $response = $this->actingAs($this->admin)->post('/repile/test', ['_token' => csrf_token()]);
        $response->assertSessionHas('flash_error_floating', 'Repile answered 500');
    }

    public function testPrivateAddressesAreRefusedAtSendTime()
    {
        \Option::set('repile.allow_private_network', false);
        $this->deliver(Events::STATUS);

        $this->assertSame([], $this->requests());
        $this->assertStringStartsWith('Not sent:', $this->record()->last_error);
    }

    public function testOnlyAdminsSeeTheStoredError()
    {
        $agent = $this->user();
        $this->mailbox->users()->syncWithoutDetaching([$agent->id]);
        $record = RepileConversation::forConversation($this->conversation->id);
        $record->last_error = 'Repile answered 401: Invalid signature';
        $record->save();

        $this->actingAs($agent);
        $agentHtml = $this->sidebar();
        $this->assertStringContainsString('Could not reach Repile', $agentHtml);
        $this->assertStringNotContainsString('Invalid signature', $agentHtml);

        $this->actingAs($this->admin);
        $this->assertStringContainsString('Invalid signature', $this->sidebar());
    }

    private function deliver($event)
    {
        (new DeliverEvent($event, $this->conversation->id))->handle();
    }

    private function record()
    {
        return RepileConversation::where('conversation_id', $this->conversation->id)->first();
    }

    private function respond($status, $body, $type = 'application/json')
    {
        file_put_contents(self::$dir.'/response.json', json_encode(['status' => $status, 'body' => $body, 'type' => $type]));
    }

    private function requests()
    {
        $lines = @file(self::$dir.'/requests.jsonl', FILE_IGNORE_NEW_LINES) ?: [];

        return array_map(function ($line) {
            return json_decode($line, true);
        }, $lines);
    }

    private function sidebar()
    {
        ob_start();
        \Eventy::action('conversation.after_prev_convs', $this->conversation->customer, $this->conversation, $this->mailbox);

        return ob_get_clean();
    }
}
