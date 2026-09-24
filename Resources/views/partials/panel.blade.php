<div class="sidebar-block repile-card">
    <div class="repile-card-head">
        <img class="repile-card-avatar" src="{{ \Modules\Repile\Support\Bot::photoUrl() }}" alt="">
        <div class="repile-card-who">
            <div class="repile-card-name">Repile</div>
            <div class="repile-card-status">
                @if ($record && $record->isWorking())
                    <span class="repile-live"></span>
                    @if ($record->working_for)
                        {{ __('Looking into :name\'s question', ['name' => $record->working_for]) }}
                    @else
                        {{ __('Looking into this ticket') }}
                    @endif
                @elseif ($record && $record->last_error)
                    @if (auth()->user() && auth()->user()->isAdmin())
                        <span class="text-danger" title="{{ $record->last_error }}">{{ __('Could not reach Repile') }}</span>
                    @else
                        <span class="text-danger">{{ __('Could not reach Repile') }}</span>
                    @endif
                @elseif ($record && $record->repile_thread_id)
                    {{ __('On this ticket') }}@if ($record->last_delivered_at) · {{ $record->last_delivered_at->diffForHumans() }}@endif
                @else
                    {{ __('Not on this ticket yet') }}
                @endif
            </div>
        </div>
        @if ($record && $record->repile_thread_id)
            <a class="repile-card-open" href="{{ $record->repileUrl() }}" target="_blank" rel="noopener" title="{{ __('Open in Repile') }}"><i class="glyphicon glyphicon-new-window"></i></a>
        @endif
    </div>
    <div class="repile-card-hint">{{ __('Mention') }} <span class="repile-mention">@Repile</span> {{ __('in a note to ask about this ticket.') }}</div>
</div>
