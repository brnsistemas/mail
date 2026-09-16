<?php

namespace App\Services;

use App\Models\MailDomain;
use Illuminate\Support\Str;
use RuntimeException;

final class DomainStatus
{
    public function __construct(private ResendGateway $provider) {}

    public function refresh(MailDomain $domain, string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('invalid_domain_id');
        }
        $data = $this->provider->request('GET', '/domains/'.$id);
        if (($data['id'] ?? null) !== $id || strtolower($data['name'] ?? '') !== $domain->domain) {
            throw new RuntimeException('provider_domain_mismatch');
        }
        $verified = ($data['status'] ?? '') === 'verified' && ($data['capabilities']['sending'] ?? '') === 'enabled';
        $domain->update(['provider_id' => $id, 'status' => $verified ? 'verified' : 'pending']);

        return ['sending_verified' => $verified, 'receiving_enabled' => ($data['capabilities']['receiving'] ?? '') === 'enabled'];
    }
}
