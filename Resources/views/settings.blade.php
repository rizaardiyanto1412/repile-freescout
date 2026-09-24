<form class="form-horizontal margin-top" method="POST" action="">
    {{ csrf_field() }}

    @if ($paid_api_active)
        <div class="alert alert-warning">
            {{ __('The API & Webhooks module is also active. If its webhook points at Repile too, Repile receives every event twice. Remove that webhook once this module is connected.') }}
        </div>
    @endif

    <h3 class="subheader">{{ __('Send tickets to Repile') }}</h3>

    <div class="form-group">
        <label for="repile_url" class="col-sm-2 control-label">{{ __('Repile URL') }}</label>
        <div class="col-sm-6">
            <input id="repile_url" type="url" class="form-control input-sized-lg" name="settings[repile.url]" value="{{ old('settings.repile.url', $settings['repile.url']) }}" placeholder="https://repile.example.com">
            <p class="form-help">
                {{ __('Where Repile runs. Events go to') }}
                <code>{{ $webhook_url ?: __('(Repile URL)').\Modules\Repile\Support\Settings::WEBHOOK_PATH }}</code>
            </p>
        </div>
    </div>

    <div class="form-group">
        <label for="repile_webhook_secret" class="col-sm-2 control-label">{{ __('Webhook secret') }}</label>
        <div class="col-sm-6">
            <input id="repile_webhook_secret" type="password" class="form-control input-sized-lg" name="settings[repile.webhook_secret]" value="{{ $settings['repile.webhook_secret'] ? str_repeat('*', 10) : '' }}" autocomplete="new-password">
            <p class="form-help">{{ __('Use the same value as "Webhook secret" in Repile under Settings, FreeScout. Every event is signed with it.') }}</p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Let Repile read and write tickets') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('FreeScout URL') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized-lg" value="{{ url('/') }}" readonly onclick="this.select()">
            <p class="form-help">{{ __('Paste this into "FreeScout URL" in Repile and pick "Repile module" as the connection.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API key') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized-lg" value="{{ $api_key }}" readonly onclick="this.select()">
            <p class="form-help">
                {{ __('Paste this into "API key" in Repile. Repile reaches') }} <code>{{ $api_url }}</code>
            </p>
            <div class="checkbox">
                <label><input type="checkbox" name="repile_regenerate_key" value="1"> {{ __('Make a new key when I save (the old key stops working)') }}</label>
            </div>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Repile user') }}</label>
        <div class="col-sm-6">
            <p class="form-control-static">
                @if ($bot_user)
                    {{ $bot_user->getFullName() }} ({{ $bot_user->email }})
                @else
                    {{ __('Created when you save. Repile writes its notes and draft replies as this user.') }}
                @endif
            </p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Last delivery') }}</label>
        <div class="col-sm-6">
            <p class="form-control-static">
                @if ($last_delivery)
                    @if (!empty($last_delivery['ok']))
                        <span class="text-success">{{ __('Delivered') }}</span>
                    @else
                        <span class="text-danger">{{ __('Failed') }}: {{ $last_delivery['error'] ?? '' }}</span>
                    @endif
                    <span class="text-help">{{ $last_delivery['event'] ?? '' }}, {{ $last_delivery['at'] ?? '' }}</span>
                @else
                    <span class="text-help">{{ __('Nothing sent yet') }}</span>
                @endif
            </p>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>

<form class="form-horizontal" method="POST" action="{{ route('repile.test') }}">
    {{ csrf_field() }}
    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-default">{{ __('Send a test event') }}</button>
            <p class="form-help">{{ __('Checks that Repile is reachable and the webhook secret matches.') }}</p>
        </div>
    </div>
</form>
