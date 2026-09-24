<div class="thread thread-type-note repile-working" data-repile-state-url="{{ route('repile.state', ['id' => $conversation->id]) }}">
    <div class="thread-photo">
        <img class="person-photo" src="{{ \Modules\Repile\Support\Bot::photoUrl() }}" alt="">
    </div>
    <div class="thread-message">
        <div class="thread-header">
            <div class="thread-title">
                <div class="thread-person">
                    <strong>Repile</strong>
                    <span class="repile-working-text">
                        @if ($record->working_for)
                            {{ __('is looking into :name\'s question', ['name' => $record->working_for]) }}
                        @else
                            {{ __('is looking into this ticket') }}
                        @endif
                    </span>
                </div>
            </div>
        </div>
        <div class="thread-body">
            <span class="repile-dots" aria-label="{{ __('Working') }}"><i></i><i></i><i></i></span>
        </div>
    </div>
</div>
