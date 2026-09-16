<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\ProviderSetting;
use App\Models\WebhookEvent;
use App\Services\ResendEventScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Svix\Webhook;

class WebhookController extends Controller
{
    public function __invoke(Request $r)
    {
        $secret = ProviderSetting::valueFor('webhook_secret');
        abort_unless($secret, 503);
        abort_if(strlen($r->getContent()) > 256 * 1024, 413);
        $headers = ['svix-id' => $r->header('svix-id'), 'svix-timestamp' => $r->header('svix-timestamp'), 'svix-signature' => $r->header('svix-signature')];
        try {
            $data = (new Webhook($secret))->verify($r->getContent(), $headers);
        } catch (\Throwable) {
            return response()->json(['message' => 'Assinatura inválida.'], 400);
        }
        if (is_object($data)) {
            $data = json_decode(json_encode($data), true);
        }
        abort_unless(is_array($data) && in_array($data['type'] ?? '', ['email.received', 'email.sent', 'email.delivered', 'email.bounced', 'email.complained', 'email.failed', 'email.delivery_delayed']), 422);
        abort_unless(is_array($data['data'] ?? null) && is_string($data['data']['email_id'] ?? null) && Str::isUuid($data['data']['email_id']) && is_string($headers['svix-id']) && strlen($headers['svix-id']) <= 200, 422);
        $hash = hash('sha256', $r->getContent());
        $existing = WebhookEvent::where('event_id', $headers['svix-id'])->first();
        if ($existing) {
            abort_unless(hash_equals($existing->body_hash, $hash), 409);

            return response()->json(['accepted' => true], 202);
        }
        if (! app(ResendEventScope::class)->accepts($data)) {
            return response()->json(['accepted' => true, 'ignored' => true], 202);
        }
        // Keep only processing metadata; shared-account events may contain subjects.
        $payload = ['type' => $data['type'], 'data' => ['email_id' => $data['data']['email_id']]];
        if ($data['type'] !== 'email.received' && ($messageId = Message::validRfcMessageId($data['data']['message_id'] ?? null))) {
            $payload['data']['message_id'] = $messageId;
        }
        if ($data['type'] === 'email.received') {
            foreach (['to', 'cc', 'bcc'] as $field) {
                $payload['data'][$field] = $data['data'][$field] ?? [];
            }
        }
        $event = WebhookEvent::firstOrCreate(['event_id' => $headers['svix-id']], ['type' => $data['type'], 'email_id' => $data['data']['email_id'], 'payload' => $payload, 'body_hash' => $hash, 'available_at' => now()]);
        abort_unless(hash_equals($event->body_hash, $hash), 409);

        return response()->json(['accepted' => true], 202);
    }
}
