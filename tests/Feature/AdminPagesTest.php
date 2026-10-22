<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminPageController;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailInvite;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use DatabaseTransactions;

    private function login(bool $master = true): User
    {
        $user = User::factory()->create(['master' => $master, 'totp_confirmed_at' => now()]);
        $this->actingAs($user)->withSession(['mfa_version' => $user->security_version]);

        return $user;
    }

    public function test_every_admin_page_requires_authentication_mfa_and_master(): void
    {
        foreach (array_keys(AdminPageController::SECTIONS) as $section) {
            $this->get('/admin/'.$section)->assertRedirect('/login');
        }
        $user = User::factory()->create(['master' => true, 'totp_confirmed_at' => now()]);
        $this->actingAs($user);
        foreach (array_keys(AdminPageController::SECTIONS) as $section) {
            $this->get('/admin/'.$section)->assertRedirect('/two-factor');
        }
        $user->forceFill(['master' => false])->save();
        $this->actingAs($user->fresh());
        $this->withSession(['mfa_version' => $user->security_version]);
        foreach (array_keys(AdminPageController::SECTIONS) as $section) {
            $this->get('/admin/'.$section)->assertForbidden();
        }
    }

    public function test_all_sections_render_but_home_does_not_embed_all_forms(): void
    {
        $this->login();
        foreach (AdminPageController::SECTIONS as $section => $details) {
            $this->get('/admin/'.$section)->assertOk()->assertSee($details[0])->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->get('/admin')->assertOk()->assertDontSee('action="/admin/grants"', false)->assertDontSee('name="api_key"', false);
        $this->get('/admin/not-a-section')->assertNotFound();
    }

    public function test_lists_paginate_in_database_preserve_filters_and_do_not_repeat_rows(): void
    {
        $this->login();
        $org = Organization::create(['name' => 'Paginação QA']);
        $otherOrg = Organization::create(['name' => 'Outra empresa QA']);
        $product = Product::create(['name' => 'Paginação QA', 'organization_id' => $org->id]);
        $domain = MailDomain::create(['domain' => 'paging.example.test', 'product_id' => $product->id, 'status' => 'local']);
        foreach (range(1, 32) as $i) {
            Mailbox::create(['address' => sprintf('page%02d@paging.example.test', $i), 'name' => 'Paginação QA', 'mail_domain_id' => $domain->id]);
        }
        $base = '/admin/mailboxes?q=paging.example.test&organization='.$org->id.'&state=active';
        $first = $this->get($base)->assertOk()->viewData('boxes');
        $second = $this->get($base.'&page=2')->assertOk()->viewData('boxes');
        $third = $this->get($base.'&page=3')->assertOk()->viewData('boxes');
        $this->assertSame(32, $first->total());
        $this->assertCount(15, $first);
        $this->assertCount(15, $second);
        $this->assertCount(2, $third);
        $this->assertEmpty($first->pluck('id')->intersect($second->pluck('id')));
        $this->assertStringContainsString('organization='.$org->id, $first->nextPageUrl());
        $this->assertStringContainsString('state=active', $first->nextPageUrl());
        $this->get('/admin/mailboxes?q=paging.example.test&organization='.$otherOrg->id)->assertOk()->assertSee('Nenhuma caixa encontrada');
        $this->get('/admin/mailboxes?q=paging.example.test&state=inactive')->assertOk()->assertSee('Nenhuma caixa encontrada');
        $this->get('/admin/mailboxes?q[]=invalid')->assertRedirect();
    }

    public function test_permission_form_scopes_company_and_never_preselects_boxes(): void
    {
        $this->login();
        $orgs = [];
        foreach (['first', 'second'] as $name) {
            $org = Organization::create(['name' => $name]);
            $product = Product::create(['name' => $name, 'organization_id' => $org->id]);
            $domain = MailDomain::create(['domain' => $name.'.permission.test', 'product_id' => $product->id]);
            Mailbox::create(['name' => $name, 'address' => 'ceo@'.$domain->domain, 'mail_domain_id' => $domain->id]);
            $orgs[] = $org;
        }
        $this->get('/admin/permissions')->assertOk()->assertDontSee('name="mailbox_ids[]"', false);
        $response = $this->get('/admin/permissions?organization='.$orgs[0]->id)->assertOk()
            ->assertSee('ceo@first.permission.test')->assertDontSee('ceo@second.permission.test');
        $this->assertDoesNotMatchRegularExpression('/name="mailbox_ids\[\]"[^>]*checked/', $response->getContent());
    }

    public function test_invites_and_activity_are_paginated_and_omit_tokens_and_payloads(): void
    {
        $this->login();
        $org = Organization::create(['name' => 'Convites QA']);
        foreach (range(1, 18) as $i) {
            MailInvite::create(['organization_id' => $org->id, 'email' => 'invite'.$i.'@pagination.test', 'token_hash' => 'SECRET_INVITE_TOKEN_'.$i, 'expires_at' => now()->addHour()]);
            DB::table('mail_audits')->insert(['actor_id' => null, 'action' => 'qa.page', 'resource_id' => 'PRIVATE_OBJECT', 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->get('/admin/invites?q=pagination.test')->assertOk()->assertDontSee('SECRET_INVITE_TOKEN');
        $this->assertCount(15, $response->viewData('records'));
        $this->assertCount(3, $this->get('/admin/invites?q=pagination.test&page=2')->viewData('records'));
        $other = Organization::create(['name' => 'Outra empresa de convites QA']);
        $this->assertSame(0, $this->get('/admin/invites?organization='.$other->id)->viewData('records')->total());
        $activity = $this->get('/admin/activity')->assertOk()->assertDontSee('PRIVATE_OBJECT');
        $this->assertCount(15, $activity->viewData('audits'));
        $this->assertStringContainsString('audits_page=2', $activity->viewData('audits')->nextPageUrl());
    }

    public function test_security_template_renders_for_enabled_and_disabled_features_without_resetting_security(): void
    {
        $user = $this->login(false);
        $version = $user->security_version;
        $this->get('/security')->assertOk()->assertSee('Segurança da conta')->assertSee('Revogar sessões')->assertDontSee('Gerenciar dispositivos')->assertDontSee('Gerenciar conexões');
        $this->assertSame($version, $user->fresh()->security_version);
        $this->assertNotNull($user->fresh()->totp_confirmed_at);
    }
}
