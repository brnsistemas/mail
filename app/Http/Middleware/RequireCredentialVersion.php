<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RequireCredentialVersion
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! $user->active || (int) $request->session()->get('credential_version', 0) !== (int) $user->credential_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->with('status', 'Seu acesso anterior foi encerrado. Use o novo convite ou entre novamente.');
        }

        return $next($request);
    }
}
