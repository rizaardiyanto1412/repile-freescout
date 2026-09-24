<?php

namespace Modules\Repile\Tests\Feature;

use App\Thread;
use Modules\Repile\Support\Events;
use Modules\Repile\Support\Payload;
use Modules\Repile\Support\Redactor;
use Modules\Repile\Support\Settings;
use Modules\Repile\Tests\Support\RepileTestCase;

class OutgoingDataTest extends RepileTestCase
{
    private const SECRETS = ['hunter2', 'u:p@', 'sk-abcdefghijklmnopqrstuv'];

    public function testPlainTextIsEscapedAsBefore()
    {
        $this->assertSame('a &lt; b<br />'."\n".'c', Payload::htmlFromText("a < b\nc"));
    }

    public function testWritesKeepSafeFormatting()
    {
        $html = '<p>Hi <strong>there</strong></p><ul><li><code>wp</code></li></ul><blockquote>q</blockquote>';
        $this->assertSame($html, Payload::htmlFromText($html));

        $link = Payload::htmlFromText('<p><a href="https://example.com/docs">docs</a></p>');
        $this->assertStringContainsString('href="https://example.com/docs"', $link);
        $this->assertStringContainsString('target="_blank"', $link);
        $this->assertStringContainsString('noopener', $link);
        $this->assertStringContainsString('noreferrer', $link);
    }

    public function testWritesDropImagesStylesAndScriptLinks()
    {
        $image = Payload::htmlFromText('<p>Look <img src="https://x.example/?d=secret"></p>');
        $this->assertStringNotContainsString('<img', $image);
        $this->assertStringNotContainsString('x.example', $image);

        $hidden = Payload::htmlFromText('<div style="display:none">hidden</div>');
        $this->assertStringNotContainsString('style', $hidden);
        $this->assertStringContainsString('hidden', $hidden);

        $script = Payload::htmlFromText('<p><a href="javascript:alert(1)">x</a></p>');
        $this->assertStringNotContainsString('javascript', $script);

        $attrs = Payload::htmlFromText('<p class="c" id="i" onclick="x()">t</p><table><tr><td>cell</td></tr></table>');
        $this->assertSame('<p>t</p>cell', $attrs);
    }

    public function testRepileNotesAreStoredSanitized()
    {
        $response = $this->api('POST', '/repile/api/conversations/'.$this->conversation->id.'/threads', [
            'type' => 'note',
            'text' => '<p>Done <img src="https://x.example/?d=1"></p>',
        ]);
        $response->assertStatus(201);
        $this->assertStringNotContainsString('<img', Thread::find($response->json()['id'])->body);
    }

    /**
     * @dataProvider redactedCases
     */
    public function testRedactorHidesCredentials($input, $expected)
    {
        $this->assertSame($expected, Redactor::scrub($input));
    }

    public function redactedCases()
    {
        return [
            ['Password: hunter2', 'Password: [redacted]'],
            ['pwd=hunter2 and more', 'pwd=[redacted] and more'],
            ['<p><strong>Password:</strong> hunter2</p>', '<p><strong>Password:</strong> [redacted]</p>'],
            ['Senha: abc123', 'Senha: [redacted]'],
            ['Kata sandi: rahasia', 'Kata sandi: [redacted]'],
            ['API key = "a b c"', 'API key = [redacted]'],
            ['token: abc.def', 'token: [redacted]'],
            ['https://u:p@site.com/wp-admin', 'https://[redacted]@site.com/wp-admin'],
            ['key sk-abcdefghijklmnopqrstuv', 'key [redacted]'],
            ['ghp_abcdefghijklmnopqrstuvwxyz1234', '[redacted]'],
            ['AKIA'.str_repeat('Q7', 8), '[redacted]'],
        ];
    }

    /**
     * @dataProvider untouchedCases
     */
    public function testRedactorLeavesOrdinaryTextAlone($input)
    {
        $this->assertSame($input, Redactor::scrub($input));
    }

    public function untouchedCases()
    {
        return [
            ['reset your password here'],
            ['I forgot my password.'],
            ['passport: 123'],
            ['compass: north'],
            ['https://site.com/wp-admin'],
            ['mail me at bob@example.com'],
            ['the task-list ask'],
        ];
    }

    public function testRedactionOffKeepsPayloadsUnchanged()
    {
        $thread = $this->thread($this->conversation, ['body' => '<p>Password: hunter2 https://u:p@site.com sk-abcdefghijklmnopqrstuv</p>']);

        $data = Payload::thread($thread);
        $this->assertSame((string) $thread->body, $data['body']);
        foreach (self::SECRETS as $secret) {
            $this->assertStringContainsString($secret, $data['body']);
        }
    }

    public function testRedactionOnScrubsApiResponsesAndEvents()
    {
        \Option::set('repile.redact_credentials', true);
        $thread = $this->thread($this->conversation, ['body' => '<p>Password: hunter2 https://u:p@site.com sk-abcdefghijklmnopqrstuv</p>']);
        $note = $this->thread($this->conversation, [
            'type' => Thread::TYPE_NOTE,
            'body' => '<p>@Repile login is Password: hunter2</p>',
            'created_by_user_id' => $this->admin->id,
            'created_by_customer_id' => null,
            'user_id' => $this->admin->id,
        ]);

        $api = $this->api('GET', '/repile/api/conversations/'.$this->conversation->id.'?_embed=threads')->getContent();
        $event = json_encode(Events::payload(Events::CUSTOMER_REPLY, $this->conversation->id, Events::thread($thread)));
        $mention = json_encode(Events::payload(Events::MENTION, $this->conversation->id, ['mention_thread_id' => $note->id]));

        foreach ([$api, $event, $mention] as $sent) {
            $this->assertStringContainsString('[redacted]', $sent);
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString(str_replace('/', '\\/', $secret), $sent);
                $this->assertStringNotContainsString($secret, $sent);
            }
        }
        $this->assertStringContainsString('hunter2', Thread::find($thread->id)->body);
    }

    public function testExcludingNotesKeepsMentionsAndRepilesOwnNotes()
    {
        $private = $this->thread($this->conversation, $this->noteBy($this->admin->id, 'Admin login is in 1Password'));
        $mention = $this->thread($this->conversation, $this->noteBy($this->admin->id, '@Repile can you check?'));
        $own = $this->thread($this->conversation, $this->noteBy($this->bot()->id, 'Looked into it'));
        $reply = $this->thread($this->conversation, ['body' => 'Customer text']);

        $this->assertEqualsCanonicalizing([$private->id, $mention->id, $own->id, $reply->id], $this->embeddedThreadIds());

        \Option::set('repile.exclude_notes', true);

        $this->assertEqualsCanonicalizing([$mention->id, $own->id, $reply->id], $this->embeddedThreadIds());
    }

    /**
     * @dataProvider urlCases
     */
    public function testRepileUrlRules($url, $allowPrivate, $ok)
    {
        $problem = Settings::urlProblem($url, $allowPrivate);
        $this->assertSame($ok, $problem === null, (string) $problem);
    }

    public function urlCases()
    {
        return [
            ['https://repile.example.com', false, true],
            ['http://repile.example.com', false, false],
            ['http://repile.example.com', true, false],
            ['ftp://repile.example.com', false, false],
            ['https://user:pass@repile.example.com', false, false],
            ['http://localhost:3000', false, false],
            ['http://localhost:3000', true, true],
            ['http://127.0.0.1:6379', false, false],
            ['http://127.0.0.1:6379', true, true],
            ['http://169.254.169.254/latest/meta-data/', false, false],
            ['https://169.254.169.254/', false, false],
            ['https://169.254.169.254/', true, true],
            ['https://10.0.0.5', false, false],
            ['https://192.168.1.10', false, false],
            ['https://[::1]:8080', false, false],
            ['http://10.0.0.5', true, false],
        ];
    }

    public function testSavingSettingsRejectsBadUrls()
    {
        $this->saveSettings(['repile.url' => 'http://repile.example.com'])->assertSessionHasErrors('repile_url');
        $this->saveSettings(['repile.url' => 'http://169.254.169.254/'])->assertSessionHasErrors('repile_url');
        $this->saveSettings(['repile.url' => 'http://127.0.0.1:6379'])->assertSessionHasErrors('repile_url');
        $this->assertSame('https://repile.test', Settings::repileUrl());

        $this->saveSettings(['repile.url' => 'http://127.0.0.1:6379', 'repile.allow_private_network' => '1'])
            ->assertSessionMissing('errors');
        $this->assertSame('http://127.0.0.1:6379', Settings::repileUrl());
        $this->assertTrue(Settings::allowsPrivateNetwork());

        $this->saveSettings(['repile.url' => 'https://repile.example.com/', 'repile.redact_credentials' => '1'])
            ->assertSessionMissing('errors');
        $this->assertSame('https://repile.example.com', Settings::repileUrl());
        $this->assertFalse(Settings::allowsPrivateNetwork());
        $this->assertTrue(Settings::redactsCredentials());
        $this->assertFalse(Settings::excludesNotes());
    }

    private function noteBy($userId, $text)
    {
        return [
            'type' => Thread::TYPE_NOTE,
            'body' => '<p>'.$text.'</p>',
            'created_by_user_id' => $userId,
            'created_by_customer_id' => null,
            'user_id' => $userId,
        ];
    }

    private function embeddedThreadIds()
    {
        $response = $this->api('GET', '/repile/api/conversations/'.$this->conversation->id.'?_embed=threads');
        $response->assertStatus(200);

        return array_map(function ($thread) {
            return $thread['id'];
        }, $response->json()['_embedded']['threads']);
    }
}
