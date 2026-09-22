<?php

namespace App\Services;

use App\Models\Membership;
use Illuminate\Support\Collection;

final class AdminMailboxInventory
{
    public function rows(Collection $boxes, Collection $users): Collection
    {
        $people = $users->keyBy('id');
        $memberships = Membership::whereIn('user_id', $people->keys())->where('active', true)
            ->get(['user_id', 'organization_id'])
            ->keyBy(fn ($membership) => $membership->user_id.':'.$membership->organization_id);

        return $boxes->map(function ($box) use ($people, $memberships) {
            $operators = $box->grants->map(function ($grant) use ($box, $people, $memberships) {
                $user = $people->get($grant->user_id);
                if (! $user) {
                    return null;
                }
                $enabled = $box->active && $user->active
                    && $memberships->has($user->id.':'.$box->domain->product->organization_id)
                    && (! $box->sensitive || $grant->view_sensitive);

                return [
                    'user' => $user,
                    'read' => $enabled && $grant->can_read,
                    'send' => $enabled && $grant->can_send,
                ];
            })->filter()->values();

            return [
                'box' => $box,
                'operators' => $operators,
                'last_login_at' => $operators->filter(fn ($operator) => $operator['read'] || $operator['send'])
                    ->map(fn ($operator) => $operator['user']->last_login_at)->filter()->sortDesc()->first(),
            ];
        });
    }
}
