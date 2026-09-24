<?php

namespace Modules\Repile\Providers;

use App\Conversation;
use Illuminate\Support\ServiceProvider;
use Modules\Repile\Entities\RepileConversation;
use Modules\Repile\Support\Bot;
use Modules\Repile\Support\Events;
use Modules\Repile\Support\Settings;

class RepileServiceProvider extends ServiceProvider
{
    public const ALIAS = 'repile';

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', self::ALIAS);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->settingsHooks();
        $this->eventHooks();
        $this->panelHooks();
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', self::ALIAS);
    }

    private function settingsHooks()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[self::ALIAS] = ['title' => 'Repile', 'icon' => 'flash', 'order' => 550];

            return $sections;
        }, 30);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== self::ALIAS) {
                return $settings;
            }

            return [
                'repile.url' => \Option::get('repile.url'),
                'repile.webhook_secret' => \Option::get('repile.webhook_secret'),
            ];
        }, 20, 2);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section !== self::ALIAS) {
                return $params;
            }
            $last = json_decode((string) \Option::get('repile.last_delivery'), true);
            $params['template_vars'] = [
                'api_key' => Settings::apiKey(),
                'api_url' => Settings::apiBaseUrl(),
                'webhook_url' => Settings::webhookUrl(),
                'bot_user' => Bot::id() ? \App\User::find(Bot::id()) : null,
                'last_delivery' => is_array($last) ? $last : null,
                'paid_api_active' => \Module::isActive('apiwebhooks'),
            ];
            $params['settings'] = [
                'repile.webhook_secret' => ['safe_password' => true],
            ];

            return $params;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section === self::ALIAS ? self::ALIAS.'::settings' : $view;
        }, 20, 2);

        \Eventy::addFilter('settings.before_save', function ($request, $section, $settings) {
            if ($section !== self::ALIAS) {
                return $request;
            }
            if ($request->filled('repile_regenerate_key')) {
                Settings::regenerateApiKey();
            }
            $values = $request->settings ?? [];
            if (isset($values['repile.url'])) {
                $values['repile.url'] = rtrim(trim((string) $values['repile.url']), '/');
            }
            if (isset($values['repile.webhook_secret'])) {
                $values['repile.webhook_secret'] = trim((string) $values['repile.webhook_secret']);
            }
            $request->merge(['settings' => $values]);

            return $request;
        }, 20, 3);

        \Eventy::addFilter('settings.after_save', function ($response, $request, $section, $settings) {
            if ($section === self::ALIAS) {
                Bot::user();
            }

            return $response;
        }, 20, 4);
    }

    private function eventHooks()
    {
        \Eventy::addAction('conversation.created_by_customer', function ($conversation, $thread, $customer) {
            Events::send(Events::CREATED, $conversation, Events::thread($thread));
        }, 20, 3);

        \Eventy::addAction('conversation.customer_replied', function ($conversation, $thread, $customer) {
            Events::send(Events::CUSTOMER_REPLY, $conversation, Events::thread($thread));
        }, 20, 3);

        \Eventy::addAction('conversation.user_replied', function ($conversation, $thread) {
            Events::send(Events::AGENT_REPLY, $conversation, Events::thread($thread));
        }, 20, 2);

        \Eventy::addAction('conversation.status_changed', function ($conversation, $user, $changed_on_reply, $prev_status) {
            Events::send(Events::STATUS, $conversation);
        }, 20, 4);

        \Eventy::addAction('conversation.deleted', function ($conversation, $user) {
            Events::send(Events::DELETED, $conversation);
        }, 20, 2);

        \Eventy::addAction('conversation.note_added', function ($conversation, $thread) {
            Events::noteAdded($conversation, $thread);
        }, 20, 2);
    }

    private function panelHooks()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(self::ALIAS).'/css/module.css';

            return $styles;
        });

        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(self::ALIAS).'/js/module.js';

            return $javascripts;
        });

        \Eventy::addAction('layout.body_bottom', function () {
            if (auth()->check()) {
                echo '<div id="repile-config" hidden data-avatar="'.e(Bot::photoUrl()).'"></div>';
            }
        });

        \Eventy::addAction('conversation.before_threads', function ($conversation) {
            if (!$conversation || !$conversation->id || !Settings::isConfigured()) {
                return;
            }
            $record = RepileConversation::where('conversation_id', $conversation->id)->first();
            if ($record && $record->isWorking()) {
                echo \View::make(self::ALIAS.'::partials.working', [
                    'conversation' => $conversation,
                    'record' => $record,
                ])->render();
            }
        }, 20, 1);

        \Eventy::addAction('conversation.append_action_buttons', function ($conversation, $mailbox) {
            if ($conversation && $conversation->id && Settings::isConfigured()) {
                echo \View::make(self::ALIAS.'::partials.menu', ['conversation' => $conversation])->render();
            }
        }, 20, 2);

        \Eventy::addAction('conversation.after_prev_convs', function ($customer, $conversation, $mailbox) {
            if (!$conversation || !$conversation->id || !Settings::isConfigured()) {
                return;
            }
            try {
                echo \View::make(self::ALIAS.'::partials.panel', [
                    'conversation' => $conversation,
                    'record' => RepileConversation::where('conversation_id', $conversation->id)->first(),
                ])->render();
            } catch (\Exception $e) {
                \Helper::logException($e, '[Repile] panel');
            }
        }, 15, 3);
    }
}
