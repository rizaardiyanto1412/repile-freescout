<div class="sidebar-block repile-panel">
    <div class="sidebar-block-header">
        <h3><i class="glyphicon glyphicon-flash"></i> Repile</h3>
    </div>
    <div class="sidebar-block-content" style="padding: 10px 15px;">
        @if ($record && $record->repile_thread_id)
            <p>
                <a href="{{ $record->repileUrl() }}" target="_blank" rel="noopener">{{ __('Open in Repile') }} <i class="glyphicon glyphicon-new-window"></i></a>
            </p>
        @else
            <p class="text-help">{{ __('Not linked to a Repile thread yet.') }}</p>
        @endif

        @if ($record && $record->last_error)
            <p class="text-danger small">
                {{ __('Last delivery failed') }}: {{ \Illuminate\Support\Str::limit($record->last_error, 200) }}
            </p>
        @elseif ($record && $record->last_delivered_at)
            <p class="text-help small">{{ __('Synced') }} {{ $record->last_delivered_at->diffForHumans() }}</p>
        @endif

        <form method="POST" action="{{ route('repile.ask', ['id' => $conversation->id]) }}" style="margin-bottom: 8px;">
            {{ csrf_field() }}
            <textarea name="question" class="form-control" rows="2" placeholder="{{ __('Ask Repile about this ticket') }}" required></textarea>
            <button type="submit" class="btn btn-primary btn-xs" style="margin-top: 6px;">{{ __('Ask Repile') }}</button>
            <span class="text-help small">{{ __('or write @Repile in a note') }}</span>
        </form>

        <form method="POST" action="{{ route('repile.recheck', ['id' => $conversation->id]) }}">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-default btn-xs"><i class="glyphicon glyphicon-refresh"></i> {{ __('Check again') }}</button>
        </form>
    </div>
</div>
