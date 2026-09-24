<?php

namespace Modules\Repile\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Repile\Entities\RepileConversation;
use Modules\Repile\Support\Events;
use Modules\Repile\Support\Settings;

class DeliverEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BACKOFF = [30, 120, 600, 1800, 7200, 21600];

    public $tries = 7;

    public $timeout = 60;

    public $event;

    public $conversationId;

    public $refs = [];

    public $payload;

    public function __construct($event, $conversationId, array $refs = [])
    {
        $this->event = $event;
        $this->conversationId = (int) $conversationId;
        $this->refs = $refs;
    }

    public function handle()
    {
        $payload = is_array($this->payload)
            ? $this->payload
            : Events::payload($this->event, $this->conversationId, $this->refs);
        $conversation_id = (int) ($payload['id'] ?? $this->conversationId);
        if ($payload === null) {
            if ($conversation_id) {
                RepileConversation::stopWorking($conversation_id);
            }

            return;
        }
        $record = RepileConversation::forConversation($conversation_id);
        $record->last_event = $this->event;

        $result = self::post($this->event, $payload);

        if ($result['ok']) {
            $body = $result['body'];
            if (is_array($body) && !empty($body['threadId']) && is_string($body['threadId'])) {
                $record->repile_thread_id = $body['threadId'];
                $record->repile_thread_path = RepileConversation::safeThreadPath($body['threadPath'] ?? null);
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
        $problem = Settings::urlProblem(Settings::repileUrl(), Settings::allowsPrivateNetwork());
        if ($problem !== null) {
            return ['ok' => false, 'retry' => false, 'error' => 'Not sent: '.$problem, 'body' => null];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-FreeScout-Event' => $event,
                    'X-FreeScout-Signature' => base64_encode(hash_hmac('sha1', $body, $secret, true)),
                    'X-Repile-Timestamp' => $timestamp,
                    'X-Repile-Signature' => self::signature($timestamp, $body, $secret),
                    'X-Repile-Delivery' => self::deliveryId(),
                    'X-Repile-Module-Version' => self::version(),
                ],
                'body' => $body,
                'timeout' => 30,
                'connect_timeout' => 10,
                'http_errors' => false,
                'allow_redirects' => false,
                'proxy' => config('app.proxy'),
            ]);
        } catch (\Exception $e) {
            \Helper::log('repile', 'Could not reach Repile for '.$event.': '.$e->getMessage());

            return ['ok' => false, 'retry' => true, 'error' => 'Could not connect to Repile', 'body' => null];
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'retry' => false, 'error' => '', 'body' => $decoded];
        }

        return [
            'ok' => false,
            'retry' => $status >= 500 || $status === 429 || $status === 408,
            'error' => self::errorMessage($status, $decoded),
            'body' => $decoded,
        ];
    }

    public static function errorMessage($status, $decoded)
    {
        $message = 'Repile answered '.(int) $status;
        if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            $error = trim(preg_replace('/\s+/', ' ', strip_tags($decoded['error'])));
            if ($error !== '') {
                $message .= ': '.mb_substr($error, 0, 200);
            }
        }

        return $message;
    }

    public static function signature($timestamp, $body, $secret)
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private static function deliveryId()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function version()
    {
        $manifest = json_decode((string) @file_get_contents(__DIR__.'/../module.json'), true);

        return is_array($manifest) && isset($manifest['version']) ? (string) $manifest['version'] : '';
    }
}
