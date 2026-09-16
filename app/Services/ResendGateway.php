<?php

namespace App\Services;

use App\Models\ProviderSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class ResendGateway
{
    public function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        $key = ProviderSetting::valueFor('api_key');
        if (! config('brnmail.external_enabled') || config('brnmail.transport') !== 'resend' || ! $key) {
            throw new RuntimeException('external_disabled');
        }
        try {
            $request = Http::withToken($key)->acceptJson()->withHeaders($headers)->timeout(25)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'stream' => true]);
            $response = $method === 'GET' ? $request->get('https://api.resend.com'.$path, $payload) : $request->post('https://api.resend.com'.$path, $payload);
            if ($response->status() === 429) {
                throw new RuntimeException('provider_rate_limited');
            }
            if ($response->serverError()) {
                throw new RuntimeException('provider_uncertain');
            }
            if (! $response->successful()) {
                throw new RuntimeException('provider_rejected');
            }
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $raw = '';
            try {
                while (! $stream->eof()) {
                    $part = $stream->read(8192);
                    if ($part === '') {
                        break;
                    } $raw .= $part;
                    if (strlen($raw) > 1024 * 1024) {
                        throw new RuntimeException('provider_response_too_large');
                    }
                }
            } finally {
                $stream->close();
            }
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                throw new RuntimeException('provider_uncertain');
            }

            return $data;
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'provider_')) {
                throw $e;
            } throw new RuntimeException('provider_uncertain');
        } catch (\Throwable) {
            throw new RuntimeException('provider_uncertain');
        }
    }

    public function received(string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('invalid_provider_id');
        }

        return $this->request('GET', '/emails/receiving/'.$id, ['html_format' => 'cid']);
    }
}
