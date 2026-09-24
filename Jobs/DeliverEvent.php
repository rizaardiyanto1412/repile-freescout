<?php

namespace Modules\Repile\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Repile\Entities\RepileConversation;
use Modules\Repile\Support\Settings;

class DeliverEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BACKOFF = [30, 120, 600, 1800, 7200, 21600];

    public $tries = 7;

    public $timeout = 60;

    public $event;

    public $payload;

    public function __construct($event, array $payload)
    {
        $this->event = $event;
        $this->payload = $payload;
    }

    public function handle()
    {
        $conversation_id = (int) ($this->payload['id'] ?? 0);
        $record = RepileConversation::forConversation($conversation_id);
        $record->last_event = $this->event;

        $result = self::post($this->event, $this->payload);

        if ($result['ok']) {
            $body = $result['body'];
            if (is_array($body) && !empty($body['threadId']) && is_string($body['threadId'])) {
                $record->repile_thread_id = $body['threadId'];
                if (!empty($body['threadPath']) && is_string($body['threadPath'])) {
                    $record->repile_thread_path = $body['threadPath'];
                }
            }
            $record->last_delivered_at = now();
            $record->last_error = null;
            $record->last_error_at = null;
            if ($conversation_id) {
                $record->save();
            }
            \Option::set('repile.last_delivery', json_encode([
                'event' => $this->event,
                'ok' => true,
                'at' => now()->toIso8601String(),
            ]));

            return;
        }

        $record->last_error = mb_substr($result['error'], 0, 2000);
        $record->last_error_at = now();
        if ($conversation_id) {
            $record->save();
        }
        \Option::set('repile.last_delivery', json_encode([
            'event' => $this->event,
            'ok' => false,
            'error' => $record->last_error,
            'at' => now()->toIso8601String(),
        ]));

        $attempt = $this->attempts();
        if ($result['retry'] && $attempt < $this->tries) {
            $this->release(self::BACKOFF[min($attempt - 1, count(self::BACKOFF) - 1)]);

            return;
        }

        if ($conversation_id) {
            RepileConversation::stopWorking($conversation_id);
        }
        \Helper::log('repile', 'Delivery of '.$this->event.' for conversation '.$conversation_id.' failed: '.$record->last_error);
    }

    public static function post($event, array $payload)
    {
        $url = Settings::webhookUrl();
        $secret = Settings::webhookSecret();
        if ($url === '' || $secret === '') {
            return ['ok' => false, 'retry' => false, 'error' => 'Repile URL or webhook secret is not set', 'body' => null];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = base64_encode(hash_hmac('sha1', $body, $secret, true));

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-FreeScout-Event' => $event,
                    'X-FreeScout-Signature' => $signature,
                    'X-Repile-Module-Version' => self::version(),
                ],
                'body' => $body,
                'timeout' => 30,
                'connect_timeout' => 10,
                'http_errors' => false,
                'proxy' => config('app.proxy'),
            ]);
        } catch (\Exception $e) {
            return ['ok' => false, 'retry' => true, 'error' => $e->getMessage(), 'body' => null];
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'retry' => false, 'error' => '', 'body' => $decoded];
        }

        $message = is_array($decoded) && !empty($decoded['error']) && is_string($decoded['error'])
            ? $decoded['error']
            : mb_substr($raw, 0, 300);

        return [
            'ok' => false,
            'retry' => $status >= 500 || $status === 429 || $status === 408,
            'error' => 'Repile answered '.$status.($message !== '' ? ': '.$message : ''),
            'body' => $decoded,
        ];
    }

    public static function version()
    {
        $manifest = json_decode((string) @file_get_contents(__DIR__.'/../module.json'), true);

        return is_array($manifest) && isset($manifest['version']) ? (string) $manifest['version'] : '';
    }
}
