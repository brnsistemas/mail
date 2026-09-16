<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RequireMfa
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! $user->active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect('/login');
        }
        if (! $user->totp_confirmed_at || (int) $request->session()->get('mfa_version') !== (int) $user->security_version) {
            return $request->expectsJson() ? response()->json(['message' => 'Segundo fator obrigatório.'], 403) : redirect('/two-factor');
        }

        return $next($request);
    }
}
