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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailboxLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Mailbox $box;

    private Mailbox $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['master' => true, 'password' => 'Local-Password-For-Tests!', 'totp_confirmed_at' => now()]);
        $org = Organization::create(['name' => 'Lifecycle QA']);
        Membership::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'active' => true]);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'QA']);
        $domain = MailDomain::create(['product_id' => $product->id, 'domain' => 'lifecycle.example.test', 'status' => 'verified']);
        foreach (['box', 'other'] as $name) {
            $this->$name = Mailbox::create(['mail_domain_id' => $domain->id, 'address' => $name.'@lifecycle.example.test', 'name' => $name]);
            MailboxGrant::create(['user_id' => $this->user->id, 'mailbox_id' => $this->$name->id, 'can_read' => true, 'can_send' => true]);
        }
        $this->actingAs($this->user)->withSession(['mfa_version' => $this->user->security_version]);
    }

    private function action(string $action, array $override = [])
    {
        $url = '/admin/mailboxes/'.$this->box->id.'/'.$action;
        $state = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->viewData('state');

        return $this->post($url, $override + ['state' => $state, 'confirm' => 1, 'password' => 'Local-Password-For-Tests!']);
    }

    public function test_block_and_unblock_preserve_permissions_messages_and_other_mailboxes(): void
    {
        $m = Message::create(['mailbox_id' => $this->box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received', 'sender' => 'sender@example.test', 'recipients' => ['to' => [$this->box->address]], 'subject' => 'Private QA', 'body_text' => 'Keep this message']);
        $version = $this->user->security_version;
        $this->action('block')->assertRedirect('/admin/mailboxes');
        $this->assertFalse(app(Access::class)->allowed($this->user, $this->box->id));
        $this->assertTrue(app(Access::class)->allowed($this->user, $this->other->id));
        $this->assertTrue((bool) $this->box->grants()->first()->can_read);
        $this->assertSame('Keep this message', $m->fresh()->body_text);
        $this->action('unblock')->assertRedirect();
        $this->assertTrue(app(Access::class)->allowed($this->user, $this->box->id));
        $this->assertSame($version, $this->user->fresh()->security_version);
    }

    public function test_revoke_only_this_mailbox_expires_pending_invites_and_keeps_receiving_enabled(): void
    {
        $invitation = MailInvite::create(['organization_id' => $this->box->domain->product->organization_id, 'mailbox_id' => $this->box->id, 'mailbox_address' => $this->box->address, 'email' => 'invite@example.test', 'token_hash' => hash('sha256', 'synthetic'), 'expires_at' => now()->addDay()]);
        $this->action('revoke')->assertRedirect();
        $this->assertTrue($this->box->fresh()->active);
        $this->assertFalse(app(Access::class)->allowed($this->user, $this->box->id));
        $this->assertTrue(app(Access::class)->allowed($this->user, $this->other->id));
        $this->assertTrue($invitation->fresh()->expires_at->lessThanOrEqualTo(now()));
        $this->assertDatabaseHas('mail_audits', ['actor_id' => $this->user->id, 'mailbox_id' => $this->box->id, 'action' => 'mailbox.revoke']);
    }

    public function test_delete_preserves_rows_address_and_restore_does_not_restore_access(): void
    {
        $this->action('delete')->assertRedirect();
        $this->assertNotNull($this->box->fresh()->deleted_at);
        $this->assertDatabaseHas('mailboxes', ['id' => $this->box->id, 'address' => $this->box->address]);
        $this->get('/admin/mailboxes')->assertDontSee($this->box->address);
        $this->get('/admin/mailboxes?state=deleted')->assertSee($this->box->address)->assertSee('Restaurar caixa');
        $this->get('/admin/permissions?organization='.$this->box->domain->product->organization_id)->assertDontSee($this->box->address);
        $this->post('/admin/grants', ['user_id' => $this->user->id, 'mailbox_ids' => [$this->box->id], 'password' => 'Local-Password-For-Tests!', 'can_read' => 1])->assertUnprocessable();
        $this->action('restore')->assertRedirect();
        $this->assertNull($this->box->fresh()->deleted_at);
        $this->assertFalse($this->box->fresh()->active);
        $this->action('unblock')->assertRedirect();
        $this->assertFalse(app(Access::class)->allowed($this->user, $this->box->id));
    }

    public function test_actions_require_master_mfa_password_confirmation_and_current_state(): void
    {
        $this->action('block', ['password' => 'wrong'])->assertUnprocessable();
        $this->action('block', ['confirm' => 0])->assertSessionHasErrors('confirm');
        $this->assertTrue($this->box->fresh()->active);
        $url = '/admin/mailboxes/'.$this->box->id.'/block';
        $state = $this->get($url)->viewData('state');
        $this->box->update(['active' => false]);
        $this->post($url, ['state' => $state, 'confirm' => 1, 'password' => 'Local-Password-For-Tests!'])->assertStatus(409);
        $this->withSession(['mfa_version' => -1])->get($url)->assertRedirect('/two-factor');
        $this->withSession(['mfa_version' => $this->user->security_version]);
        $this->user->forceFill(['master' => false])->save();
        $this->actingAs($this->user->fresh());
        $this->get($url)->assertForbidden();
        $this->post($url, ['state' => $state, 'confirm' => 1, 'password' => 'Local-Password-For-Tests!'])->assertForbidden();
        $this->assertSame(0, DB::table('mail_audits')->where('mailbox_id', $this->box->id)->where('action', 'mailbox.block')->count());
    }
}
