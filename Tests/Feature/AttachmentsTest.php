<?php

namespace Modules\Repile\Tests\Feature;

use App\Attachment;
use App\Mailbox;
use App\Thread;
use Modules\Repile\Tests\Support\RepileTestCase;

class AttachmentsTest extends RepileTestCase
{
    private $attachments = [];

    protected function tearDown(): void
    {
        foreach ($this->attachments as $attachment) {
            \Storage::delete($attachment->getStorageFilePath());
        }
        parent::tearDown();
    }

    public function testThreadsListAttachmentsAndMarkInlineImages()
    {
        $thread = $this->thread($this->conversation, ['body' => 'placeholder', 'has_attachments' => true]);
        $screenshot = $this->attach($thread, 'error screen.png', 'image/png', 'PNGDATA', true);
        $log = $this->attach($thread, 'debug.log', 'text/plain', 'Fatal error', false);
        $thread->body = '<p>It breaks here:</p><p><img src="'.$screenshot->url().'" alt="x"></p><p>Other <img src="https://cdn.example/logo.png" alt="Logo"></p>';
        $thread->save();

        $response = $this->api('GET', '/repile/api/conversations/'.$this->conversation->id.'?_embed=threads');
        $response->assertStatus(200);
        $payload = $response->json()['_embedded']['threads'][0];

        $this->assertSame([
            ['id' => $screenshot->id, 'fileName' => 'error_screen.png', 'mimeType' => 'image/png', 'size' => 7, 'inline' => true],
            ['id' => $log->id, 'fileName' => 'debug.log', 'mimeType' => 'text/plain', 'size' => 11, 'inline' => false],
        ], $payload['attachments']);
        $this->assertStringContainsString('[image: error_screen.png, attachment '.$screenshot->id.']', $payload['text']);
        $this->assertStringContainsString('[image: Logo]', $payload['text']);
    }

    public function testThreadsWithoutFilesListNone()
    {
        $this->thread($this->conversation, ['body' => '<p>Plain</p>']);

        $payload = $this->api('GET', '/repile/api/conversations/'.$this->conversation->id.'?_embed=threads')->json();
        $this->assertSame([], $payload['_embedded']['threads'][0]['attachments']);
    }

    public function testDownloadingAnAttachment()
    {
        $thread = $this->thread($this->conversation, ['body' => 'see file', 'has_attachments' => true]);
        $attachment = $this->attach($thread, 'shot.png', 'image/png', 'PNGDATA', false);

        $response = $this->api('GET', $this->url($this->conversation->id, $attachment->id));
        $response->assertStatus(200);
        $this->assertSame('PNGDATA', $response->getContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    public function testAttachmentsOfOtherConversationsAreHidden()
    {
        $other = $this->conversationIn($this->mailbox);
        $thread = $this->thread($other, ['body' => 'file', 'has_attachments' => true]);
        $attachment = $this->attach($thread, 'a.txt', 'text/plain', 'x', false);

        $this->api('GET', $this->url($this->conversation->id, $attachment->id))->assertStatus(404);
        $this->api('GET', $this->url($other->id, $attachment->id))->assertStatus(200);
    }

    public function testAttachmentsInBlockedMailboxesAndHiddenNotesAreRefused()
    {
        $note = $this->thread($this->conversation, [
            'type' => Thread::TYPE_NOTE,
            'body' => 'internal',
            'created_by_user_id' => $this->admin->id,
            'created_by_customer_id' => null,
            'has_attachments' => true,
        ]);
        $attachment = $this->attach($note, 'internal.txt', 'text/plain', 'secret', false);
        $this->api('GET', $this->url($this->conversation->id, $attachment->id))->assertStatus(200);

        \Option::set('repile.exclude_notes', true);
        \Option::$cache = [];
        $this->api('GET', $this->url($this->conversation->id, $attachment->id))->assertStatus(404);

        \Option::set('repile.exclude_notes', false);
        \Option::set('repile.mailbox_ids', [(int) factory(Mailbox::class)->create()->id]);
        \Option::$cache = [];
        $this->api('GET', $this->url($this->conversation->id, $attachment->id))->assertStatus(404);
    }

    public function testDownloadsNeedTheApiKey()
    {
        $thread = $this->thread($this->conversation, ['body' => 'file', 'has_attachments' => true]);
        $attachment = $this->attach($thread, 'a.txt', 'text/plain', 'x', false);

        $this->json('GET', $this->url($this->conversation->id, $attachment->id))->assertStatus(401);
    }

    private function attach(Thread $thread, $name, $mime, $content, $embedded)
    {
        $attachment = Attachment::create($name, $mime, null, $content, null, $embedded, $thread->id);
        $this->attachments[] = $attachment;

        return $attachment;
    }

    private function url($conversationId, $attachmentId)
    {
        return '/repile/api/conversations/'.$conversationId.'/attachments/'.$attachmentId;
    }
}
