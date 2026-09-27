@php
    $hasUrl = trim((string) $settings['repile.url']) !== '';
    $connected = $hasUrl && $last_delivery && !empty($last_delivery['ok']);
    $failing = $last_delivery && empty($last_delivery['ok']);
    $scopeAll = empty($settings['repile.mailbox_ids']);
    $deliveredAt = null;
    if ($last_delivery && !empty($last_delivery['at'])) {
        try {
            $deliveredAt = \Carbon\Carbon::parse($last_delivery['at'])->diffForHumans();
        } catch (\Exception $e) {
            $deliveredAt = null;
        }
    }
@endphp

<div class="repile-settings">
    @if ($paid_api_active)
        <div class="alert alert-warning">
            {{ __('The API & Webhooks module is also active. If its webhook points at Repile too, Repile receives every event twice. Remove that webhook once this module is connected.') }}
        </div>
    @endif

    <div class="repile-status @if ($connected) repile-status-ok @elseif ($failing) repile-status-bad @endif">
        <div class="repile-status-text">
            <div class="repile-status-title">
                <span class="repile-status-dot"></span>
                @if ($connected)
                    {{ __('Connected to Repile') }}
                @elseif ($failing)
                    {{ __('Repile could not be reached') }}
                @elseif ($hasUrl)
                    {{ __('Waiting for the first ticket') }}
                @else
                    {{ __('Not connected yet') }}
                @endif
            </div>
            <div class="repile-status-detail">
                @if ($failing)
                    {{ $last_delivery['error'] ?? '' }}
                @elseif ($last_delivery)
                    {{ __('Last event') }}: {{ $last_delivery['event'] ?? '' }}@if ($deliveredAt), {{ $deliveredAt }}@endif
                @elseif ($hasUrl)
                    {{ __('Send a test event to check the connection.') }}
                @else
                    {{ __('Follow the two steps below.') }}
                @endif
            </div>
        </div>
        @if ($hasUrl)
            <form method="POST" action="{{ route('repile.test') }}" class="repile-status-action">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-default btn-sm">{{ __('Send test event') }}</button>
            </form>
        @endif
    </div>

    <form class="repile-form" method="POST" action="">
        {{ csrf_field() }}
        <input type="hidden" name="repile_regenerate" value="">

        <section class="repile-step">
            <div class="repile-step-num">1</div>
            <div class="repile-step-body">
                <h3 class="repile-step-title">{{ __('Where is Repile?') }}</h3>
                <p class="repile-help">{{ __('The address you open Repile at.') }}</p>
                <input id="repile_url" type="url" class="form-control" name="settings[repile.url]" value="{{ old('settings.repile.url', $settings['repile.url']) }}" placeholder="https://yourteam.repile.app">
                @if ($errors->has('repile_url'))
                    <p class="text-danger repile-error">{{ $errors->first('repile_url') }}</p>
                @endif
                <details class="repile-advanced" @if ($settings['repile.allow_private_network']) open @endif>
                    <summary>{{ __('Repile runs on my own server') }}</summary>
                    <div class="checkbox">
                        <label><input type="checkbox" name="settings[repile.allow_private_network]" value="1" @if ($settings['repile.allow_private_network']) checked @endif> {{ __('Allow a private or local address') }}</label>
                    </div>
                    <p class="repile-help">{{ __('Only for Repile on this server or inside your own network. Allows private addresses, and plain http:// for localhost.') }}</p>
                </details>
            </div>
        </section>

        <section class="repile-step">
            <div class="repile-step-num">2</div>
            <div class="repile-step-body">
                <h3 class="repile-step-title">{{ __('Copy these into Repile') }}</h3>
                <p class="repile-help">{{ __('In Repile, open Settings, FreeScout, pick "Repile module" as the connection and paste each value.') }}</p>

                <div class="repile-copy-row">
                    <label for="repile_copy_url">{{ __('FreeScout URL') }}</label>
                    <div class="repile-copy">
                        <input id="repile_copy_url" type="text" class="form-control" value="{{ url('/') }}" readonly>
                        <button type="button" class="btn btn-default" data-repile-copy="#repile_copy_url">{{ __('Copy') }}</button>
                    </div>
                </div>

                <div class="repile-copy-row">
                    <label for="repile_copy_key">{{ __('API key') }}</label>
                    <div class="repile-copy">
                        <input id="repile_copy_key" type="text" class="form-control" value="{{ $api_key }}" readonly>
                        <button type="button" class="btn btn-default" data-repile-copy="#repile_copy_key">{{ __('Copy') }}</button>
                    </div>
                    <button type="button" class="btn btn-link repile-regenerate" data-repile-regenerate="api_key" data-repile-confirm="{{ __('Make a new API key? Repile stops working until you paste the new key into it.') }}">{{ __('Make a new key') }}</button>
                </div>

                <div class="repile-copy-row">
                    <label for="repile_copy_secret">{{ __('Webhook secret') }}</label>
                    <div class="repile-copy">
                        <input id="repile_copy_secret" type="text" class="form-control" value="{{ $webhook_secret }}" readonly>
                        <button type="button" class="btn btn-default" data-repile-copy="#repile_copy_secret">{{ __('Copy') }}</button>
                    </div>
                    <button type="button" class="btn btn-link repile-regenerate" data-repile-regenerate="webhook_secret" data-repile-confirm="{{ __('Make a new webhook secret? Repile rejects new tickets until you paste the new secret into it.') }}">{{ __('Make a new secret') }}</button>
                </div>
            </div>
        </section>

        <section class="repile-group">
            <h3 class="repile-group-title">{{ __('What Repile can see') }}</h3>

            <div class="repile-option">
                <div class="repile-option-label">{{ __('Mailboxes') }}</div>
                <div class="radio">
                    <label><input type="radio" name="repile_mailbox_scope" value="all" @if ($scopeAll) checked @endif> {{ __('All mailboxes') }}</label>
                </div>
                <div class="radio">
                    <label><input type="radio" name="repile_mailbox_scope" value="some" @if (!$scopeAll) checked @endif> {{ __('Only these') }}</label>
                </div>
                <div class="repile-mailboxes" data-repile-mailboxes @if ($scopeAll) hidden @endif>
                    @foreach ($mailboxes as $mailbox)
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="settings[repile.mailbox_ids][]" value="{{ $mailbox->id }}" @if (in_array((int) $mailbox->id, $settings['repile.mailbox_ids'], true)) checked @endif>
                                {{ $mailbox->name }} <span class="text-help">{{ $mailbox->email }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="repile-option">
                <div class="checkbox">
                    <label><input type="checkbox" name="settings[repile.redact_credentials]" value="1" @if ($settings['repile.redact_credentials']) checked @endif> <strong>{{ __('Hide passwords and keys') }}</strong></label>
                </div>
                <p class="repile-help">{{ __('Passwords, tokens and keys become [redacted] before Repile sees a ticket. A site login is passed to Repile separately and stored encrypted, so it can still log in without its AI reading the password. Credentials written as plain sentences can slip through.') }}</p>
            </div>

            <div class="repile-option">
                <div class="checkbox">
                    <label><input type="checkbox" name="settings[repile.exclude_notes]" value="1" @if ($settings['repile.exclude_notes']) checked @endif> <strong>{{ __('Hide internal notes') }}</strong></label>
                </div>
                <p class="repile-help">{{ __('Repile then only sees notes that mention @Repile and its own notes.') }}</p>
            </div>
        </section>

        <div class="repile-footer">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            <span class="repile-footer-note">
                @if ($bot_user)
                    {{ __('Repile writes notes and draft replies as') }} <strong>{{ $bot_user->getFullName() }}</strong>. {{ __('A person always sends the reply.') }}
                @else
                    {{ __('Saving creates a "Repile" user that writes notes and draft replies. A person always sends the reply.') }}
                @endif
            </span>
        </div>
    </form>
</div>
