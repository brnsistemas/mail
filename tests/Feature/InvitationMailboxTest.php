<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
use App\Models\MailInvite;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Access;
use App\Services\InvitationMailbox;
use App\Services\ResendEventScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class InvitationMailboxTest extends TestCase
{
    use DatabaseTransactions;

    private User $operator;

    private Organization $org;

    private MailDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['master' => true, 'password' => 'Synthetic-Admin-Password!', 'totp_confirmed_at' => now()]);
        $this->org = Organization::create(['name' => 'Convites QA']);
        $product = Product::create(['organization_id' => $this->org->id, 'name' => 'QA']);
        $this->domain = MailDomain::create(['product_id' => $product->id, 'domain' => 'invitebox.example.test', 'status' => 'verified']);
        $this->asMaster();
    }

    private function asMaster(): void
    {
        $this->actingAs($this->operator)->withSession(['mfa_version' => 1]);
    }

    private function requestData(array $extra = []): array
    {
        return array_replace(['organization_id' => $this->org->id, 'email' => 'person@invitebox.example.test', 'password' => 'Synthetic-Admin-Password!'], $extra);
    }

    private function issue(array $extra = []): array
    {
        $response = $this->post('/admin/invites', $this->requestData($extra))->assertOk()->assertSee('Convite e caixa preparados.');
        $link = $response->viewData('link');
        [$path, $token] = explode('#', $link);
        $invite = MailInvite::where('email', $extra['email'] ?? 'person@invitebox.example.test')->latest()->firstOrFail();

        return [$invite, '/invite/'.$invite->id, $token];
    }

    private function accept(string $url, string $token)
    {
        Auth::logout();
        Auth::forgetGuards();
        session()->flush();
        $this->postJson($url.'/open', ['token' => $token])->assertNoContent();
        $this->get($url)->assertOk()->assertSee('já está preparada');

        return $this->post($url, ['name' => 'Pessoa QA', 'password' => 'Synthetic-Personal-Password!', 'password_confirmation' => 'Synthetic-Personal-Password!']);
    }

    public function test_invitation_prepares_receiving_then_acceptance_and_real_mfa_open_only_the_assigned_box(): void
    {
        $other = Mailbox::create(['mail_domain_id' => $this->domain->id, 'name' => 'Privada', 'address' => 'private@invitebox.example.test']);
        [$invite, $url, $token] = $this->issue();
        $box = Mailbox::findOrFail($invite->mailbox_id);
        $this->assertTrue($box->active);
        $this->assertSame($invite->email, $box->address);
        $this->assertFalse(User::where('email', $invite->email)->exists());
        $this->assertSame(0, MailboxGrant::where('mailbox_id', $box->id)->count());
        $this->assertTrue(app(ResendEventScope::class)->accepts(['type' => 'email.received', 'data' => ['to' => [$box->address]]]));
        $message = Message::create(['mailbox_id' => $box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received', 'subject' => 'Convite QA já recebido', 'sender' => 'qa@example.test', 'body_text' => 'Conteúdo sintético', 'recipients' => ['to' => [$box->address], 'cc' => [], 'bcc' => []]]);
        $this->accept($url, $token)->assertRedirect('/two-factor')->assertSessionMissing('mfa_version');
        $user = User::where('email', $invite->email)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(app(Access::class)->allowed($user, $box->id, 'read'));
        $this->assertTrue(app(Access::class)->allowed($user, $box->id, 'send'));
        $this->assertFalse(app(Access::class)->allowed($user, $other->id));
        $this->assertFalse(app(Access::class)->allowed($this->operator, $box->id));
        $this->get('/mail?box='.$box->id)->assertRedirect('/two-factor');
        $this->get('/two-factor')->assertOk();
        $user->refresh();
        $this->post('/two-factor', ['code' => (new Google2FA)->getCurrentOtp($user->totp_secret)])->assertOk();
        Auth::forgetGuards();
        $this->get('/mail?box='.$box->id.'&message='.$message->id)->assertOk()->assertSee('Convite QA já recebido')->assertDontSee('Nenhuma caixa atribuída');
        $this->get('/mail?box='.$other->id)->assertNotFound();
        $this->get('/admin')->assertForbidden();
        $this->assertDatabaseHas('mail_audits', ['action' => 'invite.mailbox_granted', 'mailbox_id' => $box->id]);
        $this->postJson($url.'/open', ['token' => $token])->assertGone();
        $this->assertSame(1, MailboxGrant::where('user_id', $user->id)->count());
    }

    public function test_external_login_can_have_an_explicit_company_box_but_no_external_domain_is_created(): void
    {
        [$invite, $url, $token] = $this->issue(['email' => 'external@example.test', 'mailbox_address' => 'person@invitebox.example.test']);
        $this->accept($url, $token)->assertRedirect('/two-factor');
        $user = User::where('email', 'external@example.test')->firstOrFail();
        $this->assertTrue(app(Access::class)->allowed($user, $invite->mailbox_id));
        $this->assertDatabaseMissing('mail_domains', ['domain' => 'example.test']);
    }

    public function test_missing_or_wrong_admin_confirmation_never_creates_invite_or_box(): void
    {
        foreach (['', 'Wrong-Synthetic-Password!'] as $password) {
            $this->post('/admin/invites', $this->requestData(['password' => $password]))->assertSessionHasErrors('password');
        }
        $this->assertDatabaseMissing('mailboxes', ['address' => 'person@invitebox.example.test']);
        $this->assertSame(0, MailInvite::where('organization_id', $this->org->id)->count());
    }

    public function test_wrong_company_pending_domain_and_alias_fail_without_partial_writes(): void
    {
        $foreign = Organization::create(['name' => 'Outra empresa']);
        $this->post('/admin/invites', $this->requestData(['organization_id' => $foreign->id]))->assertSessionHasErrors('mailbox_address');
        $this->domain->update(['status' => 'pending']);
        $this->post('/admin/invites', $this->requestData())->assertSessionHasErrors('mailbox_address');
        $this->domain->update(['status' => 'verified']);
        $box = Mailbox::create(['mail_domain_id' => $this->domain->id, 'name' => 'Shared', 'address' => 'shared@invitebox.example.test']);
        DB::table('mail_aliases')->insert(['address' => 'person@invitebox.example.test', 'mailbox_id' => $box->id]);
        $this->post('/admin/invites', $this->requestData(['reuse_mailbox' => true]))->assertSessionHasErrors('mailbox_address');
        $this->assertSame(0, MailInvite::where('organization_id', $this->org->id)->count());
        $this->assertDatabaseMissing('mailboxes', ['address' => 'person@invitebox.example.test']);
    }

    public function test_existing_box_requires_explicit_history_access_and_sensitive_or_disabled_boxes_are_rejected(): void
    {
        $box = Mailbox::create(['mail_domain_id' => $this->domain->id, 'name' => 'Existing', 'address' => 'person@invitebox.example.test']);
        $this->post('/admin/invites', $this->requestData())->assertSessionHasErrors('mailbox_address');
        $box->update(['sensitive' => true]);
        $this->post('/admin/invites', $this->requestData(['reuse_mailbox' => true]))->assertSessionHasErrors('mailbox_address');
        $box->update(['sensitive' => false, 'active' => false]);
        $this->post('/admin/invites', $this->requestData(['reuse_mailbox' => true]))->assertSessionHasErrors('mailbox_address');
        $box->update(['active' => true]);
        [$invite, $url, $token] = $this->issue(['reuse_mailbox' => true]);
        $this->assertSame($box->id, $invite->mailbox_id);
        $this->accept($url, $token)->assertRedirect('/two-factor');
        $this->assertSame(1, Mailbox::where('address', $box->address)->count());
    }

    public function test_duplicate_invites_and_existing_accounts_are_rejected_without_new_resources(): void
    {
        [$invite] = $this->issue();
        $this->post('/admin/invites', $this->requestData(['reuse_mailbox' => true]))->assertSessionHasErrors('email');
        $this->assertSame(1, MailInvite::where('email', $invite->email)->count());
        $this->assertSame(1, Mailbox::where('address', $invite->email)->count());
        $existing = User::factory()->create(['email' => 'existing@invitebox.example.test']);
        $this->post('/admin/invites', $this->requestData(['email' => $existing->email]))->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('mailboxes', ['address' => $existing->email]);
    }

    public function test_mailbox_reassignment_or_revocation_before_acceptance_rolls_back_account_creation(): void
    {
        [$invite, $url, $token] = $this->issue();
        $box = Mailbox::findOrFail($invite->mailbox_id);
        foreach ([['active' => false], ['active' => true, 'sensitive' => true], ['sensitive' => false, 'address' => 'renamed@invitebox.example.test']] as $change) {
            $box->update($change);
            $this->accept($url, $token)->assertStatus(302)->assertSessionHasErrors('mailbox_address');
            $this->assertFalse(User::where('email', $invite->email)->exists());
            $this->assertNull($invite->fresh()->accepted_at);
            $this->assertSame(0, MailboxGrant::where('mailbox_id', $box->id)->count());
            $this->travel(61)->seconds();
        }
    }

    public function test_expired_invite_does_not_grant_access_and_keeps_received_mailbox_data(): void
    {
        [$invite, $url, $token] = $this->issue();
        $invite->update(['expires_at' => now()->subMinute()]);
        Auth::logout();
        $this->post($url, ['token' => $token, 'name' => 'QA', 'password' => 'Synthetic-Personal-Password!', 'password_confirmation' => 'Synthetic-Personal-Password!'])->assertGone();
        $this->assertNotNull(Mailbox::find($invite->mailbox_id));
        $this->assertSame(0, MailboxGrant::where('mailbox_id', $invite->mailbox_id)->count());
    }

    private function legacy(): array
    {
        $user = User::factory()->create(['email' => 'legacy@invitebox.example.test', 'totp_secret' => (new Google2FA)->generateSecretKey(32), 'totp_confirmed_at' => now(), 'security_version' => 7]);
        Membership::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'active' => true]);
        $invite = MailInvite::create(['organization_id' => $this->org->id, 'email' => $user->email, 'token_hash' => hash('sha256', 'synthetic'), 'expires_at' => now()->subDay(), 'accepted_at' => now()->subDays(2)]);

        return [$user, $invite];
    }

    public function test_scoped_legacy_repair_is_idempotent_and_preserves_password_mfa_and_other_grants(): void
    {
        [$user, $invite] = $this->legacy();
        $user->refresh();
        $original = $user->getRawOriginal();
        $args = ['email' => $user->email, '--operator' => $this->operator->id, '--organization' => $this->org->id, '--provision-personal-mailbox' => true];
        $this->artisan('brnmail:repair-invited-mailbox', $args)->assertSuccessful();
        $this->artisan('brnmail:repair-invited-mailbox', $args)->assertSuccessful();
        $user->refresh();
        foreach (['password', 'totp_secret', 'totp_confirmed_at', 'credential_version', 'name'] as $field) {
            $this->assertSame($original[$field], $user->getRawOriginal($field));
        }
        $this->assertSame(8, (int) $user->security_version);
        $box = Mailbox::where('address', $user->email)->firstOrFail();
        $this->assertSame($box->id, $invite->fresh()->mailbox_id);
        $this->assertSame(1, MailboxGrant::where('user_id', $user->id)->count());
        $this->assertTrue(app(Access::class)->allowed($user, $box->id));
        $this->assertDatabaseHas('mail_audits', ['actor_id' => $this->operator->id, 'action' => 'invite.mailbox_repaired', 'mailbox_id' => $box->id]);
    }

    public function test_legacy_repair_refuses_suspended_identity_revoked_membership_or_existing_box(): void
    {
        [$user] = $this->legacy();
        $service = app(InvitationMailbox::class);
        foreach (['inactive', 'revoked', 'existing'] as $case) {
            $user->forceFill(['active' => $case !== 'inactive'])->save();
            Membership::where('user_id', $user->id)->update(['active' => $case !== 'revoked']);
            if ($case === 'existing') {
                Mailbox::create(['mail_domain_id' => $this->domain->id, 'name' => 'Existing', 'address' => $user->email]);
            }
            try {
                $service->repair($this->operator->id, $user->email, $this->org->id);
                $this->fail('Unexpected legacy access granted');
            } catch (ValidationException) {
                $this->assertSame(0, MailboxGrant::where('user_id', $user->id)->count());
            }
        }
    }
}
