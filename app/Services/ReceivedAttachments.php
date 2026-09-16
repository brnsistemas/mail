<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ReceivedAttachments
{
    public function __construct(private ResendGateway $provider, private Attachments $files) {}

    public function process(Attachment $a): void
    {
        if ($a->status !== 'unavailable' || $a->message->direction !== 'inbound' || ! Str::isUuid($a->provider_id ?? '')) {
            return;
        }
        if (! config('brnmail.external_enabled')) {
            return;
        }
        $tmp = null;
        try {
            if (! $this->files->metadataAllowed($a->filename, $a->mime) || $a->size > config('brnmail.attachment_max')) {
                $a->update(['status' => 'blocked', 'reason' => 'format_or_size_not_allowed']);

                return;
            }
            $id = $a->message->provider_id;
            $info = $this->provider->request('GET', '/emails/receiving/'.$id.'/attachments/'.$a->provider_id);
            if (($info['id'] ?? null) !== $a->provider_id) {
                throw new RuntimeException('attachment_id_mismatch');
            }
            $bytes = $this->download((string) ($info['download_url'] ?? ''));
            if ($a->size > 0 && strlen($bytes) !== $a->size) {
                throw new RuntimeException('attachment_size_mismatch');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'brnmail-anexo-');
            chmod($tmp, 0600);
            file_put_contents($tmp, $bytes);
            $upload = new UploadedFile($tmp, $a->filename, null, null, true);
            $this->files->validateFile($upload);
            DB::transaction(function () use ($a, $bytes) {
                Message::whereKey($a->message_id)->lockForUpdate()->firstOrFail();
                $locked = Attachment::whereKey($a->id)->lockForUpdate()->first();
                if ($locked->status !== 'unavailable') {
                    return;
                }
                $stored = Attachment::where('message_id', $a->message_id)->whereNotNull('path')->sum('size');
                if ($stored + strlen($bytes) > config('brnmail.attachments_total')) {
                    $locked->update(['status' => 'blocked', 'reason' => 'attachment_limits_exceeded']);

                    return;
                }
                $path = 'mail/'.$a->id.'.bin';
                Storage::disk('local')->put($path, Crypt::encryptString($bytes));
                $locked->update(['path' => $path, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'quarantine', 'reason' => null]);
            });
            $a->refresh();
            if ($a->status === 'quarantine') {
                $this->files->scan($a, $bytes);
            }
        } catch (\Throwable) {
            $a->update(['reason' => 'attachment_retrieval_failed']);
        } finally {
            if ($tmp && is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function download(string $url): string
    {
        $host = $this->downloadHost($url);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            if ($ip = $record['ip'] ?? $record['ipv6'] ?? null) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new RuntimeException('attachment_download_ip_rejected');
                }
                $ips[] = $ip;
            }
        }
        if (! $ips) {
            throw new RuntimeException('attachment_download_dns_failed');
        }
        $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        return $this->downloadFromPinnedIp($url, $host, $ip);
    }

    public function downloadHost(string $url): string
    {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443) || $host !== 'inbound-cdn.resend.com') {
            throw new RuntimeException('attachment_download_host_rejected');
        }

        return $host;
    }

    private function downloadFromPinnedIp(string $url, string $host, string $ip): string
    {
        // Pin the resolved address, disable redirects and never forward the Resend API key.
        $res = Http::timeout(20)->connectTimeout(4)->withOptions(['stream' => true, 'allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip]]])->get($url);
        if (! $res->successful()) {
            throw new RuntimeException('attachment_download_failed');
        }
        $stream = $res->toPsrResponse()->getBody();
        $max = config('brnmail.attachment_max');
        $bytes = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                } $bytes .= $chunk;
                if (strlen($bytes) > $max) {
                    throw new RuntimeException('attachment_download_too_large');
                }
            }
        } finally {
            $stream->close();
        }
        if ($bytes === '') {
            throw new RuntimeException('attachment_download_empty');
        }

        return $bytes;
    }
}
