<?php

namespace Modules\Repile\Http\Controllers;

use App\Conversation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Repile\Entities\RepileConversation;
use Modules\Repile\Jobs\DeliverEvent;
use Modules\Repile\Support\Events;
use Modules\Repile\Support\Payload;
use Modules\Repile\Support\Settings;

class PanelController extends Controller
{
    public function recheck(Request $request, $id)
    {
        $conversation = $this->authorizedConversation($request, $id);
        RepileConversation::startWorking($conversation->id, $request->user()->first_name);
        Events::send(Events::RECHECK, $conversation, [
            'requestedBy' => Payload::user($request->user()),
        ]);

        return response()->json(['ok' => true]);
    }

    public function state(Request $request, $id)
    {
        $conversation = $this->authorizedConversation($request, $id);
        $record = RepileConversation::where('conversation_id', $conversation->id)->first();

        return response()->json(['working' => $record ? $record->isWorking() : false]);
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
