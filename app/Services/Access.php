<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class Access
{
    public function mailboxes(User $user, string $ability = 'read'): Builder
    {
        abort_unless(in_array($ability, ['read', 'send', 'manage']), 403);

        return Mailbox::query()->where('active', true)->whereHas('domain.product', fn ($q) => $q->whereIn('organization_id', Membership::where('user_id', $user->id)->where('active', true)->select('organization_id')))
            ->whereHas('grants', fn ($q) => $q->where('user_id', $user->id)->where('can_'.$ability, true)->where(fn ($q) => $q->where('view_sensitive', true)->orWhere('mailboxes.sensitive', false)))
            ->when(! $user->active, fn ($q) => $q->whereRaw('1=0'));
    }

    public function mailbox(User $user, int $id, string $ability = 'read'): Mailbox
    {
        return $this->mailboxes($user, $ability)->with('domain.product')->findOrFail($id);
    }

    public function allowed(User $user, int $id, string $ability = 'read'): bool
    {
        return $this->mailboxes($user, $ability)->whereKey($id)->exists();
    }

    public function audit(?User $user, string $action, ?int $mailbox = null, ?string $resource = null): void
    {
        DB::table('mail_audits')->insert(['actor_id' => $user?->id, 'mailbox_id' => $mailbox, 'action' => $action, 'resource_id' => $resource, 'created_at' => now(), 'updated_at' => now()]);
    }
}
