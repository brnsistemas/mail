<?php

namespace App\Http\Controllers;

use App\Services\Access;
use App\Services\Mfa;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:200']);
        $key = 'login:'.hash('sha256', strtolower($d['email']).'|'.$r->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Aguarde antes de tentar novamente.']);
        }
        RateLimiter::hit($key, 300);
        if (! Auth::attempt(['email' => strtolower($d['email']), 'password' => $d['password'], 'active' => true])) {
            return back()->withErrors(['email' => 'Não foi possível entrar com esses dados.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();
        $r->session()->forget('mfa_version');

        return redirect('/two-factor');
    }

    public function factor(Request $r)
    {
        $u = $r->user();
        abort_unless($u->active, 403);
        if (! $u->totp_secret) {
            $u->totp_secret = (new Google2FA)->generateSecretKey(32);
            $u->save();
        }

        $setup = ! $u->totp_confirmed_at;
        $qr = null;
        if ($setup) {
            // Provisioning stays in memory: no QR service, URL, temporary image,
            // or request log receives the TOTP secret.
            $uri = (new Google2FA)->getQRCodeUrl('BRN Mail', $u->email, $u->totp_secret);
            $writer = new Writer(new ImageRenderer(new RendererStyle(232, 4), new SvgImageBackEnd));
            $qr = preg_replace('/^<\?xml[^>]+>\s*/', '', $writer->writeString($uri));
        }

        return view('auth.factor', ['setup' => $setup, 'secret' => $setup ? $u->totp_secret : null, 'qr' => $qr]);
    }

    public function verify(Request $r, Mfa $mfa, Access $access)
    {
        $r->validate(['code' => 'required|string|max:100']);
        $u = $r->user();
        if (! $mfa->verify($u, (string) $r->string('code'))) {
            return back()->withErrors(['code' => 'Código inválido, expirado ou já utilizado.']);
        }
        $u->refresh();
        $codes = null;
        if (! $u->totp_confirmed_at) {
            $u->totp_confirmed_at = now();
            $u->save();
            $codes = $mfa->recovery($u);
        }
        $r->session()->regenerate();
        $r->session()->put('mfa_version', $u->security_version);
        $access->audit($u, 'mfa.verified');

        return $codes ? view('auth.recovery', ['codes' => $codes]) : redirect('/mail');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function revoke(Request $r, Mfa $mfa, Access $access)
    {
        $r->validate(['password' => 'required|string', 'code' => 'required|string|max:100']);
        abort_unless(Hash::check((string) $r->string('password'), $r->user()->password) && $mfa->verify($r->user(), (string) $r->string('code')), 422);
        $r->user()->increment('security_version');
        $access->audit($r->user(), 'sessions.revoked');

        return $this->logout($r);
    }
}
