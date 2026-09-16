<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Mfa;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaExperienceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_setup_encodes_the_current_user_locally_and_keeps_secrets_out_of_urls(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/two-factor')->assertOk();
        $user->refresh();
        $this->assertNotNull($user->totp_secret);
        $this->assertNull($user->totp_confirmed_at);

        $uri = (new Google2FA)->getQRCodeUrl('BRN Mail', $user->email, $user->totp_secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(232, 4), new SvgImageBackEnd));
        $expected = preg_replace('/^<\?xml[^>]+>\s*/', '', $writer->writeString($uri));
        $response->assertViewHas('qr', $expected)
            ->assertSee('class="totp-qr"', false)
            ->assertSee('id="copy-totp-key"', false)
            ->assertSee('id="totp-key"', false)
            ->assertDontSee('otpauth://', false);
        $this->assertStringNotContainsString($user->totp_secret, $expected);
        $this->assertStringNotContainsString('<script', $expected);
        $this->assertStringContainsString('<svg', $expected);
        $this->assertStringContainsString('<path', $expected);
        $this->assertStringNotContainsString('href=', $expected);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString($user->totp_secret, DB::table('users')->where('id', $user->id)->value('totp_secret'));
        Http::assertNothingSent();
        $this->get('/mail')->assertRedirect('/two-factor');
    }

    public function test_confirmed_authenticator_never_redisplays_enrollment_secret_or_qr(): void
    {
        $user = User::factory()->create();
        $user->totp_secret = (new Google2FA)->generateSecretKey(32);
        $user->totp_confirmed_at = now();
        $user->save();

        $this->actingAs($user)->get('/two-factor')->assertOk()
            ->assertViewHas('setup', false)
            ->assertViewHas('qr', null)
            ->assertViewHas('secret', null)
            ->assertDontSee($user->totp_secret, false)
            ->assertDontSee('class="totp-qr"', false)
            ->assertDontSee('id="copy-totp-key"', false);
        Http::assertNothingSent();
    }

    public function test_recovery_download_is_available_only_on_initial_confirmation_and_codes_remain_single_use(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/two-factor')->assertOk();
        $user->refresh();
        $otp = (new Google2FA)->getCurrentOtp($user->totp_secret);

        $response = $this->post('/two-factor', ['code' => $otp])->assertOk()
            ->assertSee('id="download-recovery-codes"', false)
            ->assertSee('sem criptografia')
            ->assertSee('não serão mostrados novamente');
        $codes = $response->viewData('codes');
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        $user->refresh();
        $this->assertNotNull($user->totp_confirmed_at);
        $this->assertCount(8, $user->recovery_hashes);
        foreach ($codes as $i => $code) {
            $this->assertTrue(Hash::check($code, $user->recovery_hashes[$i]));
            $this->assertStringNotContainsString($code, DB::table('users')->where('id', $user->id)->value('recovery_hashes'));
            $this->assertStringNotContainsString($code, json_encode(session()->all()));
        }
        $this->get('/two-factor')->assertOk()
            ->assertDontSee('id="download-recovery-codes"', false)
            ->assertDontSee($codes[0], false);
        $this->post('/two-factor', ['code' => $codes[0]])->assertRedirect('/mail');
        $this->assertCount(7, $user->fresh()->recovery_hashes);
        $this->assertFalse(app(Mfa::class)->verify($user, $codes[0]));
        Http::assertNothingSent();
    }

    public function test_a_guest_or_disabled_user_cannot_request_an_enrollment_qr(): void
    {
        $this->get('/two-factor')->assertRedirect('/login');
        $user = User::factory()->create();
        $user->active = false;
        $user->save();
        $this->actingAs($user)->get('/two-factor')->assertForbidden();
        $this->assertNull($user->fresh()->totp_secret);
    }
}
