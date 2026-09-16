<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class MessageContent
{
    public function text(?string $text, ?string $html): string
    {
        if (is_string($text) && trim($text) !== '') {
            if (mb_strlen($text) > 200000) {
                throw new RuntimeException('message_body_limit');
            }

            return $text;
        }
        // No remote images, links, active markup or browser HTML rendering.
        $safe = (new HtmlSanitizer((new HtmlSanitizerConfig)->allowSafeElements()->dropElement('script')->dropElement('style')->dropElement('img')))->sanitize($html ?? '');
        $safe = preg_replace('/<\/(p|div|li|h[1-6])>|<br\s*\/?>/i', "\n", $safe);

        $plain = html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (mb_strlen($plain) > 200000) {
            throw new RuntimeException('message_body_limit');
        }

        return $plain;
    }

    public function words(string $text): array
    {
        return array_slice(array_values(array_unique(array_filter(preg_split('/[^\pL\pN@.]+/u', mb_strtolower($text)), fn ($w) => mb_strlen($w) > 1))), 0, 512);
    }

    public function digest(int $mailbox, string $word): string
    {
        return hash_hmac('sha256', $mailbox.'|'.$word, config('app.key'));
    }

    public function index(Message $m): void
    {
        DB::table('message_search')->where('message_id', $m->id)->delete();
        $rows = array_map(fn ($word) => ['message_id' => $m->id, 'digest' => $this->digest($m->mailbox_id, $word)], $this->words($m->subject.' '.$m->sender.' '.$m->body_text));
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('message_search')->insertOrIgnore($chunk);
        }
    }
}
