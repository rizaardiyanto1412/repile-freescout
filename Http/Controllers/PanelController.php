<?php

namespace Modules\Repile\Http\Controllers;

use App\Conversation;
use App\Thread;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Repile\Jobs\DeliverEvent;
use Modules\Repile\Support\Events;
use Modules\Repile\Support\Payload;
use Modules\Repile\Support\Settings;

class PanelController extends Controller
{
    public function ask(Request $request, $id)
    {
        $conversation = $this->authorizedConversation($request, $id);
        $question = trim((string) $request->input('question', ''));
        if ($question === '') {
            return redirect()->back()->with('flash_error_floating', __('Type a question for Repile first.'));
        }
        $body = nl2br(e('@Repile '.$question));
        $conversation->createUserThread($request->user(), $body, ['type' => Thread::TYPE_NOTE]);

        return redirect()->back()->with('flash_success_floating', __('Asked Repile. The answer arrives as a note.'));
    }

    public function recheck(Request $request, $id)
    {
        $conversation = $this->authorizedConversation($request, $id);
        Events::send(Events::RECHECK, $conversation, [
            'requestedBy' => Payload::user($request->user()),
        ]);

        return redirect()->back()->with('flash_success_floating', __('Repile is checking this ticket again.'));
    }

    public function test(Request $request)
    {
        if (!Settings::isConfigured()) {
            return redirect()->back()->with('flash_error_floating', __('Save the Repile URL and webhook secret first.'));
        }
        $result = DeliverEvent::post('repile.ping', [
            'id' => 0,
            'ping' => true,
            'freescoutUrl' => url('/'),
            'apiUrl' => Settings::apiBaseUrl(),
            'moduleVersion' => DeliverEvent::version(),
        ]);
        if ($result['ok']) {
            return redirect()->back()->with('flash_success_floating', __('Repile answered and the webhook secret matches.'));
        }

        return redirect()->back()->with('flash_error_floating', e($result['error']));
    }

    private function authorizedConversation(Request $request, $id)
    {
        $conversation = Conversation::findOrFail((int) $id);
        if (!$request->user()->can('view', $conversation)) {
            abort(403);
        }

        return $conversation;
    }
}
