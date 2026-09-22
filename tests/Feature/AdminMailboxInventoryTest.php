<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
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

class AdminMailboxInventoryTest extends TestCase
{
    use DatabaseTransactions;

    private function master(): User
    {
        $master = User::factory()->create(['master' => true, 'totp_confirmed_at' => now()]);
        $this->actingAs($master)->withSession(['mfa_version' => 1]);

        return $master;
    }

    private function box(string $name, array $attributes = []): Mailbox
    {
        $organization = Organization::create(['name' => 'Empresa '.$name]);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'Produto '.$name]);
        $domain = MailDomain::create(['product_id' => $product->id, 'domain' => $name.'.test', 'status' => 'local']);

        return Mailbox::create(array_merge(['mail_domain_id' => $domain->id, 'name' => 'Caixa '.$name, 'address' => 'equipe@'.$name.'.test'], $attributes));
    }

    private function grant(User $user, Mailbox $box, array $attributes = []): void
    {
        Membership::firstOrCreate(['user_id' => $user->id, 'organization_id' => $box->domain->product->organization_id], ['active' => true]);
        MailboxGrant::create(array_merge(['user_id' => $user->id, 'mailbox_id' => $box->id, 'can_read' => true, 'can_send' => true], $attributes));
    }

    private function audit(User $user, string $action, string $at): void
    {
        DB::table('mail_audits')->insert(['actor_id' => $user->id, 'action' => $action, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_master_sees_all_metadata_without_receiving_access_to_mail_or_attachments(): void
    {
        $master = $this->master();
        $active = $this->box('administrativo');
        $inactive = $this->box('outraempresa', ['active' => false]);
        $operator = User::factory()->create(['name' => 'Operador QA']);
        $this->grant($operator, $active);
        $message = Message::create(['mailbox_id' => $active->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received', 'subject' => 'Assunto confidencial QA', 'sender' => 'externo@example.test', 'body_text' => 'Corpo confidencial QA', 'recipients' => ['to' => [$active->address], 'cc' => [], 'bcc' => []]]);
        $attachment = Attachment::create(['message_id' => $message->id, 'filename' => 'privado.txt', 'mime' => 'text/plain', 'size' => 10, 'status' => 'clean']);
        $grants = MailboxGrant::count();
        $memberships = Membership::count();

        $this->get('/admin')->assertOk()->assertSee($active->address)->assertSee($inactive->address)
            ->assertSee('Empresa outraempresa')->assertSee('Inativa')->assertSee($operator->email)
            ->assertDontSee('Assunto confidencial QA')->assertDontSee('Corpo confidencial QA')->assertDontSee('privado.txt');
        $this->get('/mail?box='.$active->id.'&message='.$message->id)->assertNotFound();
        $this->get('/attachments/'.$attachment->id)->assertNotFound();
        $this->post('/drafts', ['mailbox_id' => $active->id])->assertNotFound();
        $this->assertFalse(app(Access::class)->allowed($master, $active->id));
        $this->assertSame($grants, MailboxGrant::count());
        $this->assertSame($memberships, Membership::count());
    }

    public function test_inventory_requires_master_and_completed_mfa(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $master = User::factory()->create(['master' => true, 'totp_confirmed_at' => now()]);
        $this->actingAs($master)->get('/admin')->assertRedirect('/two-factor');
        $regular = User::factory()->create(['totp_confirmed_at' => now()]);
        $this->actingAs($regular)->withSession(['mfa_version' => 1])->get('/admin')->assertForbidden();
    }

    public function test_last_login_uses_the_latest_completed_mfa_from_full_history_and_omits_secrets(): void
    {
        $this->master();
        $box = $this->box('historico');
        $operator = User::factory()->create(['totp_secret' => 'SYNTHETIC_PRIVATE_SECRET', 'recovery_hashes' => ['SYNTHETIC_RECOVERY_HASH']]);
        $withoutLogin = User::factory()->create(['active' => false]);
        $this->grant($operator, $box);
        $this->audit($operator, 'mfa.verified', '2026-09-18 11:00:00');
        $this->audit($operator, 'mfa.verified', '2026-09-19 12:30:00');
        foreach (range(1, 25) as $unused) {
            $this->audit($operator, 'message.viewed', '2026-09-20 13:00:00');
        }
        $this->audit($operator, 'login.password', '2026-09-20 14:00:00');
        $this->audit($withoutLogin, 'login.failed', '2026-09-20 14:00:00');

        $response = $this->get('/admin')->assertOk()->assertSee('19/09/2026 12:30:00')->assertSee($withoutLogin->email)->assertSee('Sem registro');
        $users = $response->viewData('users')->keyBy('id');
        $this->assertSame('2026-09-19 12:30:00', $users[$operator->id]->last_login_at->format('Y-m-d H:i:s'));
        $this->assertNull($users[$withoutLogin->id]->last_login_at);
        foreach (['password', 'remember_token', 'totp_secret', 'recovery_hashes'] as $secret) {
            $this->assertArrayNotHasKey($secret, $users[$operator->id]->getAttributes());
        }
        $response->assertDontSee('SYNTHETIC_PRIVATE_SECRET')->assertDontSee('SYNTHETIC_RECOVERY_HASH');
        $row = $response->viewData('inventory')->firstWhere('box.id', $box->id);
        $this->assertSame('2026-09-19 12:30:00', $row['last_login_at']->format('Y-m-d H:i:s'));
    }

    public function test_effective_operators_and_latest_login_respect_every_access_boundary(): void
    {
        $this->master();
        $shared = $this->box('compartilhada');
        $sensitive = $this->box('sensivel', ['sensitive' => true]);
        $inactive = $this->box('inativa', ['active' => false]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $disabled = User::factory()->create(['active' => false]);
        $revoked = User::factory()->create();
        $noReadSend = User::factory()->create();
        foreach ([$first, $second, $disabled, $revoked] as $person) {
            $this->grant($person, $shared);
        }
        $this->grant($noReadSend, $shared, ['can_read' => false, 'can_send' => false]);
        Membership::where('user_id', $revoked->id)->update(['active' => false]);
        $this->grant($first, $sensitive, ['view_sensitive' => false]);
        $this->grant($second, $sensitive, ['view_sensitive' => true, 'can_send' => false]);
        $this->grant($second, $inactive);
        $this->audit($first, 'mfa.verified', '2026-09-18 11:00:00');
        $this->audit($second, 'mfa.verified', '2026-09-19 11:00:00');
        foreach ([$disabled, $revoked, $noReadSend] as $person) {
            $this->audit($person, 'mfa.verified', '2026-09-20 11:00:00');
        }

        $rows = $this->get('/admin')->assertOk()->viewData('inventory')->keyBy('box.id');
        foreach ([$shared, $sensitive, $inactive] as $box) {
            foreach ($rows[$box->id]['operators'] as $operator) {
                $this->assertSame(app(Access::class)->allowed($operator['user'], $box->id, 'read'), $operator['read']);
                $this->assertSame(app(Access::class)->allowed($operator['user'], $box->id, 'send'), $operator['send']);
            }
        }
        $this->assertSame('2026-09-19 11:00:00', $rows[$shared->id]['last_login_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-19 11:00:00', $rows[$sensitive->id]['last_login_at']->format('Y-m-d H:i:s'));
        $this->assertNull($rows[$inactive->id]['last_login_at']);
    }

    public function test_inventory_escapes_user_and_mailbox_names(): void
    {
        $this->master();
        $box = $this->box('escape', ['name' => '<script>mailboxTest()</script>']);
        $operator = User::factory()->create(['name' => '<script>userTest()</script>']);
        $this->grant($operator, $box);
        $this->get('/admin')->assertOk()->assertSee($box->name)->assertSee($operator->name)
            ->assertDontSee($box->name, false)->assertDontSee($operator->name, false);
    }
}
