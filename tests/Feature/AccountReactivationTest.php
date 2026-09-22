<?php

namespace Tests\Feature;

use App\Models\MailInvite;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountReactivation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AccountReactivationTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(): array
    {
        $operator = User::factory()->create(['master' => true]);
        $user = User::factory()->create(['password' => 'Old-Synthetic-Password!',
            'totp_secret' => (new Google2FA)->generateSecretKey(32), 'totp_confirmed_at' => now(),
            'totp_last_step' => 123, 'recovery_hashes' => [Hash::make('old-recovery-code')], 'remember_token' => 'old-remember-token']);
        $org = Organization::create(['name' => 'Reactivation QA']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'member', 'active' => true]);

        return [$operator, $user, $org];
    }

    private function credentials(): array
    {
        return ['name' => 'Should not replace existing name', 'password' => 'Chosen-New-Password!', 'password_confirmation' => 'Chosen-New-Password!'];
    }

    public function test_revocation_invalidates_credentials_and_preserves_identity_membership_and_grants(): void
    {
        [$operator, $user, $org] = $this->fixture();
        $user->refresh();
        $original = $user->getRawOriginal();
        $membership = DB::table('memberships')->where('user_id', $user->id)->get()->toJson();
        $product = DB::table('products')->insertGetId(['organization_id' => $org->id, 'name' => 'QA']);
        $domain = DB::table('mail_domains')->insertGetId(['product_id' => $product, 'domain' => 'reactivation.example.test']);
        $box = DB::table('mailboxes')->insertGetId(['mail_domain_id' => $domain, 'name' => 'QA', 'address' => 'qa@reactivation.example.test']);
        DB::table('mailbox_grants')->insert(['user_id' => $user->id, 'mailbox_id' => $box, 'can_read' => true, 'can_send' => false]);
        $grants = DB::table('mailbox_grants')->where('user_id', $user->id)->get()->toJson();
        $pending = MailInvite::create(['organization_id' => $org->id, 'email' => $user->email,
            'token_hash' => hash('sha256', 'synthetic-old'), 'expires_at' => now()->addDay()]);
        [$invite, $token] = app(AccountReactivation::class)->issue($operator->id, $user->email, $org->id);
        $user->refresh();
        $this->assertFalse($user->active);
        $this->assertFalse(Hash::check('Old-Synthetic-Password!', $user->password));
        foreach (['totp_secret', 'totp_confirmed_at', 'totp_last_step', 'recovery_hashes', 'remember_token'] as $field) {
            $this->assertNull($user->$field);
        }
        $this->assertSame(2, (int) $user->security_version);
        $this->assertSame(1, (int) $user->credential_version);
        foreach (['id', 'name', 'email', 'created_at', 'master'] as $field) {
            $this->assertSame($original[$field], $user->getRawOriginal($field));
        }
        $this->assertSame($membership, DB::table('memberships')->where('user_id', $user->id)->get()->toJson());
        $this->assertSame($grants, DB::table('mailbox_grants')->where('user_id', $user->id)->get()->toJson());
        $this->assertTrue($pending->fresh()->expires_at->isPast());
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertStringNotContainsString($token, $invite->toJson());
        $this->assertDatabaseHas('mail_audits', ['actor_id' => $operator->id, 'action' => 'user.reactivation_issued', 'resource_id' => (string) $user->id]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Old-Synthetic-Password!'])->assertSessionHasErrors('email');
    }

    public function test_invitee_chooses_password_sets_new_mfa_and_keeps_same_identity(): void
    {
        [$operator, $user, $org] = $this->fixture();
        $oldSecret = $user->totp_secret;
        $oldName = $user->name;
        [$invite, $token] = app(AccountReactivation::class)->issue($operator->id, $user->email, $org->id);
        $url = '/invite/'.$invite->id;
        $this->get($url)->assertDontSee($user->email)->assertDontSee('name="password"', false);
        $this->postJson($url.'/open', ['token' => $token])->assertNoContent();
        $this->get($url)->assertSee('Definir minha senha e configurar o autenticador')->assertDontSee('Confirme sua senha atual');
        $this->from($url)->post($url, array_replace($this->credentials(), ['password_confirmation' => 'Mismatch']))
            ->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
        $this->assertFalse($user->fresh()->active);
        $this->post($url, $this->credentials())->assertRedirect('/two-factor')->assertSessionHas('credential_version', 1)->assertSessionMissing('mfa_version');
        $user->refresh();
        $this->assertTrue($user->active);
        $this->assertSame($oldName, $user->name);
        $this->assertSame(1, User::where('email', $user->email)->count());
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('Chosen-New-Password!', $user->password));
        $this->get('/mail')->assertRedirect('/two-factor');
        $this->get('/two-factor')->assertOk()->assertViewHas('setup', true);
        $user->refresh();
        $this->assertNotSame($oldSecret, $user->totp_secret);
        $this->post('/two-factor', ['code' => 'old-recovery-code'])->assertSessionHasErrors('code');
        $code = (new Google2FA)->getCurrentOtp($user->totp_secret);
        $this->post('/two-factor', ['code' => $code])->assertOk()->assertViewIs('auth.recovery')->assertViewHas('codes', fn ($codes) => count($codes) === 8);
        Auth::forgetGuards();
        $this->get('/mail')->assertOk();
        $this->get('/admin')->assertForbidden();
        $this->assertSame(0, DB::table('mailbox_grants')->where('user_id', $user->id)->count());
        $this->post('/logout');
        Auth::forgetGuards();
        $this->post($url, $this->credentials() + ['token' => $token])->assertGone();
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'Chosen-New-Password!'])->assertRedirect('/two-factor')->assertSessionHas('credential_version', 1);
    }

    public function test_old_sessions_remain_blocked_after_reactivation_even_on_mfa_routes(): void
    {
        [$operator, $user, $org] = $this->fixture();
        [$invite, $token] = app(AccountReactivation::class)->issue($operator->id, $user->email, $org->id);
        $url = '/invite/'.$invite->id;
        $this->post($url, $this->credentials() + ['token' => $token])->assertRedirect('/two-factor');
        foreach ([['GET', '/two-factor'], ['POST', '/two-factor'], ['GET', '/mail']] as [$method, $path]) {
            $this->actingAs($user->fresh())->withSession(['credential_version' => 0, 'mfa_version' => $user->fresh()->security_version]);
            $this->call($method, $path, ['code' => '000000'])->assertRedirect('/login');
            $this->assertGuest();
        }
        $this->assertNull($user->fresh()->totp_confirmed_at);
    }

    public function test_reactivation_does_not_inherit_another_logged_in_identity(): void
    {
        [$operator, $user, $org] = $this->fixture();
        [$invite, $token] = app(AccountReactivation::class)->issue($operator->id, $user->email, $org->id);
        $this->actingAs($operator)->withSession(['mfa_version' => 1, 'private.previous' => true]);
        $this->post('/invite/'.$invite->id, $this->credentials() + ['token' => $token])->assertRedirect('/two-factor')
            ->assertSessionMissing('mfa_version')->assertSessionMissing('private.previous');
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertFalse(Auth::user()->master);
    }

    public function test_wrong_token_expiry_generation_email_and_revoked_membership_fail_closed(): void
    {
        [$operator, $user, $org] = $this->fixture();
        [$invite, $token] = app(AccountReactivation::class)->issue($operator->id, $user->email, $org->id);
        $url = '/invite/'.$invite->id;
        $this->post($url, $this->credentials() + ['token' => str_repeat('0', 64)])->assertGone();
        $invite->update(['expires_at' => now()->subMinute()]);
        $this->post($url, $this->credentials() + ['token' => $token])->assertGone();
        $invite->update(['expires_at' => now()->addHour(), 'reactivation_version' => 99]);
        $this->post($url, $this->credentials() + ['token' => $token])->assertGone();
        $invite->update(['reactivation_version' => 1, 'email' => 'other@example.test']);
        $this->post($url, $this->credentials() + ['token' => $token])->assertGone();
        $invite->update(['email' => $user->email]);
        Membership::where('user_id', $user->id)->update(['active' => false]);
        $this->post($url, $this->credentials() + ['token' => $token])->assertGone();
        $this->assertFalse($user->fresh()->active);
        $this->assertNull($invite->fresh()->accepted_at);
        $this->assertGuest();
    }

    public function test_reactivation_requires_master_and_existing_active_membership_and_refuses_master_target(): void
    {
        [$operator, $user, $org] = $this->fixture();
        $service = app(AccountReactivation::class);
        foreach ([[$user->id, $user->email, $org->id], [$operator->id, $operator->email, $org->id], [$operator->id, $user->email, 999999]] as $args) {
            try {
                $service->issue(...$args);
                $this->fail('Unauthorized reactivation succeeded');
            } catch (ModelNotFoundException|ValidationException) {
                $this->assertTrue($user->fresh()->active);
                $this->assertSame(0, (int) $user->fresh()->credential_version);
            }
        }
    }

    public function test_pending_reactivation_is_not_duplicated(): void
    {
        [$operator, $user, $org] = $this->fixture();
        $service = app(AccountReactivation::class);
        [$invite] = $service->issue($operator->id, $user->email, $org->id);
        try {
            $service->issue($operator->id, $user->email, $org->id);
            $this->fail('Duplicate accepted');
        } catch (ValidationException) {
            $this->assertSame(1, MailInvite::where('reactivation_user_id', $user->id)->count());
            $this->assertSame(1, (int) $user->fresh()->credential_version);
            $this->assertFalse($invite->fresh()->expires_at->isPast());
        }
    }

    public function test_operator_command_writes_only_to_private_file_and_never_prints_token(): void
    {
        [$operator, $user, $org] = $this->fixture();
        $directory = sys_get_temp_dir().'/brnmail-reactivation-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/invite.html';
        try {
            $this->artisan('brnmail:reactivate-user', ['email' => $user->email, '--operator' => $operator->id,
                '--organization' => $org->id, '--output' => $path, '--revoke-and-reinvite' => true])
                ->expectsOutput('Acesso anterior revogado. Convite gravado no arquivo privado indicado. Senha e 2FA serão definidos pelo titular; nenhum e-mail foi enviado.')
                ->assertSuccessful();
            $this->assertSame(0600, fileperms($path) & 0777);
            $contents = file_get_contents($path);
            $this->assertMatchesRegularExpression('/href="[^" ]+#[a-f0-9]{64}"/', $contents);
            $this->assertFalse($user->fresh()->active);
        } finally {
            @unlink($path);
            rmdir($directory);
        }
    }

    public function test_legacy_session_of_unaffected_account_remains_valid(): void
    {
        [$operator] = $this->fixture();
        $operator->forceFill(['totp_confirmed_at' => now()])->save();
        $this->actingAs($operator)->withSession(['mfa_version' => $operator->security_version]);
        $this->get('/admin')->assertOk();
    }
}
