<?php

namespace Tests\Feature;

use App\Models\MailInvite;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InviteFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function invitation(?User $user = null): array
    {
        $org = Organization::create(['name' => 'Equipe QA convite']);
        $token = bin2hex(random_bytes(32));
        $invite = MailInvite::create(['organization_id' => $org->id, 'email' => $user?->email ?? 'invite-qa@example.test',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);

        return [$invite, $token, '/invite/'.$invite->id];
    }

    private function credentials(array $overrides = []): array
    {
        return array_replace(['name' => 'Pessoa QA', 'password' => 'Synthetic-Invite-Password!',
            'password_confirmation' => 'Synthetic-Invite-Password!'], $overrides);
    }

    public function test_opening_and_reloading_keep_the_verified_invitation_without_revealing_the_token(): void
    {
        [$invite, $token, $url] = $this->invitation();
        $this->get($url)->assertOk()->assertDontSee($invite->email)->assertDontSee('name="password"', false);
        $this->postJson($url.'/open', ['token' => $token])->assertNoContent()
            ->assertSessionHas('mail_invites.'.$invite->id, hash('sha256', $token));
        $this->assertStringNotContainsString($token, json_encode(session()->all()));
        for ($i = 0; $i < 2; $i++) {
            $this->get($url)->assertOk()->assertSee($invite->email)->assertSee('Equipe QA convite')->assertDontSee($token);
        }
        $this->assertNull($invite->fresh()->accepted_at);
        $this->assertSame(0, Membership::where('organization_id', $invite->organization_id)->count());
    }

    public function test_validation_retry_succeeds_without_resending_the_fragment(): void
    {
        [$invite, $token, $url] = $this->invitation();
        $this->postJson($url.'/open', ['token' => $token])->assertNoContent();
        $this->from($url)->post($url, $this->credentials(['password_confirmation' => 'Mismatch']))
            ->assertRedirect($url)->assertSessionHasErrors(['password'])
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.token');
        $this->get($url)->assertSee('A confirmação da senha não confere.')->assertSee($invite->email);
        $this->post($url, $this->credentials())->assertRedirect('/login')->assertSessionMissing('mail_invites.'.$invite->id);
        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertSame(1, Membership::where('organization_id', $invite->organization_id)->count());
        $user = User::where('email', $invite->email)->firstOrFail();
        $this->assertSame(0, DB::table('mailbox_grants')->where('user_id', $user->id)->count());
        $this->postJson($url.'/open', ['token' => $token])->assertGone();
    }

    public function test_existing_identity_keeps_password_mfa_and_security_version_after_wrong_password_retry(): void
    {
        $user = User::factory()->create(['email' => 'existing-invite@example.test', 'password' => 'Synthetic-Invite-Password!',
            'totp_secret' => 'SYNTHETIC-TOTP-FOR-QA', 'totp_confirmed_at' => now(), 'security_version' => 7]);
        $original = $user->getRawOriginal();
        [$invite, $token, $url] = $this->invitation($user);
        $this->postJson($url.'/open', ['token' => $token])->assertNoContent();
        $this->get($url)->assertSee('Sua senha atual do BRN Mail');
        $this->from($url)->post($url, $this->credentials(['password' => 'Wrong-Synthetic-Password!', 'password_confirmation' => 'Wrong-Synthetic-Password!']))
            ->assertRedirect($url)->assertSessionHasErrors(['password']);
        $this->post($url, $this->credentials())->assertRedirect('/login');
        $user->refresh();
        foreach (['password', 'totp_secret', 'totp_confirmed_at', 'security_version', 'name'] as $field) {
            $this->assertSame($original[$field], $user->getRawOriginal($field));
        }
        $this->assertSame(1, User::where('email', $invite->email)->count());
    }

    public function test_proof_is_scoped_to_the_invitation_and_browser_session(): void
    {
        [$first, $token, $url] = $this->invitation();
        [$second, , $otherUrl] = $this->invitation();
        $this->postJson($url.'/open', ['token' => $token])->assertNoContent();
        $this->get($otherUrl)->assertDontSee($second->email);
        $this->post($otherUrl, $this->credentials())->assertSessionHasErrors(['invite']);
        session()->forget('mail_invites');
        $this->get($url)->assertDontSee($first->email);
        $this->post($url, $this->credentials())->assertSessionHasErrors(['invite']);
        $this->assertNull($first->fresh()->accepted_at);
        $this->assertNull($second->fresh()->accepted_at);
    }

    public function test_wrong_token_expiry_and_consumption_are_rechecked(): void
    {
        [$invite, $token, $url] = $this->invitation();
        $this->postJson($url.'/open', ['token' => str_repeat('0', 64)])->assertGone();
        $this->postJson($url.'/open', ['token' => $token])->assertNoContent();
        $invite->update(['expires_at' => now()->subMinute()]);
        $this->post($url, $this->credentials())->assertGone();
        $invite->update(['expires_at' => now()->addHour(), 'accepted_at' => now()]);
        $this->post($url, $this->credentials())->assertGone();
        $this->get($url)->assertGone();
    }

    public function test_missing_link_is_explained_in_portuguese(): void
    {
        [, , $url] = $this->invitation();
        $this->from($url)->post($url, $this->credentials())->assertRedirect($url)->assertSessionHasErrors(['invite']);
        $this->get($url)->assertSee('Abra o link completo do convite para continuar.')->assertDontSee('The token field is required.');
    }
}
