<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

final class Mfa
{
    public function verify(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $fresh = User::lockForUpdate()->findOrFail($user->id);
            if (! $fresh->active || ! $fresh->totp_secret) {
                return false;
            }
            if (preg_match('/^\d{6}$/D', $code)) {
                $step = (new Google2FA)->verifyKeyNewer($fresh->totp_secret, $code, $fresh->totp_last_step ?? 0, 1);
                if ($step === false) {
                    return false;
                }
                $fresh->totp_last_step = $step;
                $fresh->save();

                return true;
            }
            if (! $fresh->totp_confirmed_at) {
                return false;
            }
            $hashes = $fresh->recovery_hashes ?? [];
            foreach ($hashes as $i => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($hashes[$i]);
                    $fresh->recovery_hashes = array_values($hashes);
                    $fresh->save();

                    return true;
                }
            }

            return false;
        });
    }

    public function recovery(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = bin2hex(random_bytes(10));
        }
        $user->recovery_hashes = array_map(fn ($c) => Hash::make($c), $codes);
        $user->save();

        return $codes;
    }
}
