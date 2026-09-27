<?php

namespace Modules\Repile\Tests\Feature;

use Modules\Repile\Support\Settings;
use Modules\Repile\Tests\Support\RepileTestCase;

class SettingsPageTest extends RepileTestCase
{
    public function testThePageRendersAfterADeliveryWasRecorded()
    {
        \Option::set('repile.last_delivery', json_encode(['event' => 'convo.created', 'ok' => true, 'at' => now()->toIso8601String()]));
        \Option::$cache = [];
        $this->assertSame('convo.created', Settings::lastDelivery()['event']);

        \Option::set('repile.last_delivery', ['event' => 'convo.status', 'ok' => false, 'error' => 'HTTP 502', 'at' => now()->toIso8601String()]);
        \Option::$cache = [];

        $page = $this->actingAs($this->admin)->get('/app-settings/repile');
        $page->assertStatus(200);
        $this->assertStringContainsString('Repile could not be reached', $page->getContent());
        $this->assertStringContainsString('HTTP 502', $page->getContent());
    }

    public function testASecretIsCreatedForCopyingAndCanBeReplaced()
    {
        \Option::set('repile.webhook_secret', '');
        \Option::$cache = [];

        $page = $this->actingAs($this->admin)->get('/app-settings/repile');
        $page->assertStatus(200);
        $secret = Settings::webhookSecret();
        $this->assertSame(48, strlen($secret));
        $this->assertStringContainsString($secret, $page->getContent());
        $key = Settings::apiKey();

        $this->actingAs($this->admin)->post('/app-settings/repile', [
            'settings' => ['repile.url' => 'https://repile.test', 'repile.webhook_secret' => str_repeat('*', 10)],
            'repile_regenerate' => 'webhook_secret',
            '_token' => csrf_token(),
        ]);
        \Option::$cache = [];
        $this->assertNotSame($secret, Settings::webhookSecret());
        $this->assertSame(48, strlen(Settings::webhookSecret()));
        $this->assertSame($key, Settings::apiKey());
    }

    public function testChoosingAllMailboxesClearsTheList()
    {
        $this->saveSettings(['repile.mailbox_ids' => [(string) $this->mailbox->id]]);
        $this->assertSame([(int) $this->mailbox->id], Settings::mailboxIds());

        $this->actingAs($this->admin)->post('/app-settings/repile', [
            'settings' => [
                'repile.url' => 'https://repile.test',
                'repile.webhook_secret' => str_repeat('*', 10),
                'repile.mailbox_ids' => [(string) $this->mailbox->id],
            ],
            'repile_mailbox_scope' => 'all',
            '_token' => csrf_token(),
        ]);
        \Option::$cache = [];
        $this->assertSame([], Settings::mailboxIds());
    }
}
