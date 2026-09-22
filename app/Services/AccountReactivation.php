<?php

namespace App\Services;

use App\Models\MailInvite;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountReactivation
{
    /** Trusted operator action. The plaintext token is returned once, never logged. */
    public function issue(int $operatorId, string $email, int $organizationId): array
    {
        return DB::transaction(function () use ($operatorId, $email, $organizationId) {
            $operator = User::whereKey($operatorId)->where('active', true)->where('master', true)->firstOrFail();
            $user = User::where('email', strtolower($email))->lockForUpdate()->firstOrFail();
            if ($user->master || $user->id === $operator->id || ! Membership::where('user_id', $user->id)->where('organization_id', $organizationId)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['user' => 'A reativação exige uma conta de colaborador e um vínculo ativo já autorizado.']);
            }
            if (MailInvite::where('reactivation_user_id', $user->id)->whereNull('accepted_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['user' => 'Já existe um convite de reativação pendente. Não foi criado outro.']);
            }
            MailInvite::where('email', $user->email)->whereNull('accepted_at')->update(['expires_at' => now()->subSecond()]);
            $user->forceFill([
                'active' => false,
                'password' => bin2hex(random_bytes(32)),
                'remember_token' => null,
                'totp_secret' => null,
                'totp_confirmed_at' => null,
                'totp_last_step' => null,
                'recovery_hashes' => null,
                'security_version' => (int) $user->security_version + 1,
                'credential_version' => (int) $user->credential_version + 1,
            ])->save();
            $token = bin2hex(random_bytes(32));
            $invite = MailInvite::create([
                'organization_id' => $organizationId,
                'email' => $user->email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(48),
                'reactivation_user_id' => $user->id,
                'reactivation_version' => $user->credential_version,
            ]);
            app(Access::class)->audit($operator, 'user.reactivation_issued', null, (string) $user->id);

            return [$invite, $token];
        }, 3);
    }

    /** Called inside the locked invitation transaction; never creates grants. */
    public function accept(MailInvite $invite, #[\SensitiveParameter] string $password): ?User
    {
        $user = User::whereKey($invite->reactivation_user_id)->lockForUpdate()->first();
        if (! $user || $user->master || $user->active || $user->email !== $invite->email
            || (int) $user->credential_version !== (int) $invite->reactivation_version
            || ! Membership::where('user_id', $user->id)->where('organization_id', $invite->organization_id)->where('active', true)->exists()) {
            return null;
        }
        // Memberships, grants, name, identity and message references are preserved.
        $user->forceFill([
            'password' => $password,
            'active' => true,
            'remember_token' => null,
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'totp_last_step' => null,
            'recovery_hashes' => null,
        ])->save();
        app(Access::class)->audit($user, 'user.reactivation_accepted', null, $invite->id);

        return $user;
    }
}
