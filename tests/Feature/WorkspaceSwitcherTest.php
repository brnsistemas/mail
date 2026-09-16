<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WorkspaceSwitcherTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Mailbox $first;

    private Mailbox $second;

    private Mailbox $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['totp_confirmed_at' => now()]);
        $this->first = $this->workspace('alpha', $this->user);
        $this->second = $this->workspace('beta', $this->user);
        $this->foreign = $this->workspace('confidencial', User::factory()->create());
        $this->actingAs($this->user)->withSession(['mfa_version' => 1]);
    }

    private function workspace(string $name, User $user): Mailbox
    {
        $organization = Organization::create(['name' => 'Empresa '.ucfirst($name)]);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'active' => true]);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'Produto '.ucfirst($name)]);
        $domain = MailDomain::create(['product_id' => $product->id, 'domain' => $name.'.test', 'status' => 'local']);
        $box = Mailbox::create(['mail_domain_id' => $domain->id, 'name' => 'Equipe '.ucfirst($name), 'address' => 'equipe@'.$name.'.test']);
        MailboxGrant::create(['user_id' => $user->id, 'mailbox_id' => $box->id, 'can_read' => true, 'can_send' => true]);

        return $box;
    }

    private function message(Mailbox $box, array $extra = []): Message
    {
        return Message::create(array_merge([
            'mailbox_id' => $box->id,
            'thread_id' => (string) Str::uuid(),
            'direction' => 'inbound',
            'folder' => 'inbox',
            'status' => 'received',
            'subject' => 'Conteúdo exclusivo '.$box->name,
            'sender' => 'Pessoa <pessoa@example.test>',
            'body_text' => 'Mensagem sintética do QA de isolamento.',
            'recipients' => ['to' => [$box->address], 'cc' => [], 'bcc' => []],
        ], $extra));
    }

    private function selector(TestResponse $response): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $switchers = $xpath->query('//*[@data-testid="workspace-switcher"]');
        $this->assertCount(1, $switchers, 'O seletor deve ter um único ponto de entrada acessível.');
        $options = $xpath->query('.//*[@data-testid="workspace-option"]', $switchers->item(0));
        $ids = [];
        foreach ($options as $option) {
            $ids[] = (int) $option->getAttribute('data-mailbox-id');
        }

        return ['ids' => $ids, 'text' => $switchers->item(0)->textContent];
    }

    public function test_selector_lists_only_authorized_companies_domains_and_mailboxes(): void
    {
        $response = $this->get('/mail?box='.$this->first->id)->assertOk();
        $selector = $this->selector($response);
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], $selector['ids']);
        $this->assertStringContainsString('Empresa Alpha', $selector['text']);
        $this->assertStringContainsString('alpha.test', $selector['text']);
        $this->assertStringContainsString('Empresa Beta', $selector['text']);
        $this->assertStringContainsString('beta.test', $selector['text']);
        $response->assertDontSee('Empresa Confidencial')->assertDontSee('confidencial.test')->assertDontSee($this->foreign->address);
    }

    public function test_one_login_can_switch_between_two_authorized_companies_without_merging_messages(): void
    {
        $alpha = $this->message($this->first);
        $beta = $this->message($this->second);

        $this->get('/mail?box='.$this->first->id)->assertOk()
            ->assertViewHas('box', fn ($box) => $box->id === $this->first->id)
            ->assertSee($alpha->subject)->assertDontSee($beta->subject)
            ->assertSessionHas('mail.active_box', $this->first->id);
        $this->get('/mail?box='.$this->second->id)->assertOk()
            ->assertViewHas('box', fn ($box) => $box->id === $this->second->id)
            ->assertSee($beta->subject)->assertDontSee($alpha->subject)
            ->assertSessionHas('mail.active_box', $this->second->id);
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_bare_mail_url_resumes_the_last_authorized_mailbox(): void
    {
        $this->get('/mail?box='.$this->second->id)->assertOk();
        $this->get('/mail')->assertOk()->assertViewHas('box', fn ($box) => $box->id === $this->second->id);
    }

    public function test_explicit_box_urls_keep_two_tabs_independent_of_the_shared_session_preference(): void
    {
        $alpha = $this->message($this->first);
        $beta = $this->message($this->second);
        $alphaUrl = '/mail?box='.$this->first->id.'&message='.$alpha->id;
        $betaUrl = '/mail?box='.$this->second->id.'&message='.$beta->id;

        $this->get($alphaUrl)->assertOk()->assertViewHas('selected', fn ($message) => $message->id === $alpha->id);
        $this->get($betaUrl)->assertOk()->assertViewHas('selected', fn ($message) => $message->id === $beta->id);
        $this->get($alphaUrl)->assertOk()->assertViewHas('selected', fn ($message) => $message->id === $alpha->id)
            ->assertViewHas('box', fn ($box) => $box->id === $this->first->id);
    }

    public function test_bare_url_pins_pagination_and_refresh_to_its_resolved_mailbox(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->message($this->first, ['subject' => 'Mensagem paginável '.$i]);
        }
        $this->withSession(['mail.active_box' => $this->first->id]);
        $response = $this->get('/mail')->assertOk();
        $next = $response->viewData('messages')->nextPageUrl();
        $this->assertNotNull($next);
        parse_str(parse_url($next, PHP_URL_QUERY), $nextQuery);
        $this->assertSame((string) $this->first->id, $nextQuery['box'] ?? null);
        $this->assertSame('inbox', $nextQuery['folder'] ?? null);
        $this->assertSame('2', $nextQuery['page'] ?? null);

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $refreshLinks = (new \DOMXPath($document))->query('//a[contains(concat(" ", normalize-space(@class), " "), " refresh ")]');
        $this->assertCount(1, $refreshLinks);
        $refresh = $refreshLinks->item(0)->getAttribute('href');
        parse_str(parse_url($refresh, PHP_URL_QUERY), $refreshQuery);
        $this->assertSame((string) $this->first->id, $refreshQuery['box'] ?? null);
        $this->assertSame('inbox', $refreshQuery['folder'] ?? null);

        // Another tab changes the session preference after the first page rendered.
        $this->get('/mail?box='.$this->second->id)->assertOk();
        $this->get($next)->assertOk()->assertViewHas('box', fn ($box) => $box->id === $this->first->id)
            ->assertViewHas('messages', fn ($messages) => $messages->currentPage() === 2 && $messages->total() === 26);
        $this->get('/mail?box='.$this->second->id)->assertOk();
        $this->get($refresh)->assertOk()->assertViewHas('box', fn ($box) => $box->id === $this->first->id);
    }

    public function test_revoked_grant_is_removed_from_selector_and_saved_preference_falls_back(): void
    {
        $this->get('/mail?box='.$this->second->id)->assertOk();
        MailboxGrant::where('user_id', $this->user->id)->where('mailbox_id', $this->second->id)->delete();

        $response = $this->get('/mail')->assertOk()
            ->assertViewHas('box', fn ($box) => $box->id === $this->first->id)
            ->assertSessionHas('mail.active_box', $this->first->id);
        $this->assertSame([$this->first->id], $this->selector($response)['ids']);
        $response->assertDontSee('Empresa Beta')->assertDontSee('beta.test');
        $this->get('/mail?box='.$this->second->id)->assertNotFound();
    }

    public function test_revoked_membership_overrides_a_retained_mailbox_grant_and_session(): void
    {
        $this->get('/mail?box='.$this->second->id)->assertOk();
        Membership::where('user_id', $this->user->id)
            ->where('organization_id', $this->second->domain->product->organization_id)->update(['active' => false]);

        $response = $this->get('/mail')->assertOk()
            ->assertViewHas('box', fn ($box) => $box->id === $this->first->id);
        $this->assertSame([$this->first->id], $this->selector($response)['ids']);
        $this->get('/mail?box='.$this->second->id)->assertNotFound();
    }

    public function test_unknown_or_unauthorized_explicit_mailbox_does_not_silently_fall_back(): void
    {
        $this->withSession(['mail.active_box' => $this->first->id]);
        $this->get('/mail?box='.$this->foreign->id)->assertNotFound()
            ->assertSessionHas('mail.active_box', $this->first->id);
        $this->get('/mail?box=99999999')->assertNotFound()
            ->assertSessionHas('mail.active_box', $this->first->id);
    }

    public function test_foreign_saved_preference_never_grants_access(): void
    {
        $this->withSession(['mail.active_box' => $this->foreign->id]);
        $this->get('/mail')->assertOk()
            ->assertViewHas('box', fn ($box) => $box->id === $this->first->id)
            ->assertSessionHas('mail.active_box', $this->first->id)
            ->assertDontSee($this->foreign->address);
    }

    public function test_a_message_from_another_authorized_box_still_requires_matching_box_context(): void
    {
        $beta = $this->message($this->second);
        $this->withSession(['mail.active_box' => $this->first->id]);
        $this->get('/mail?box='.$this->first->id.'&message='.$beta->id)->assertNotFound()
            ->assertSessionHas('mail.active_box', $this->first->id);
    }

    public function test_invalid_message_or_folder_does_not_change_last_successful_context(): void
    {
        $this->withSession(['mail.active_box' => $this->first->id]);
        $this->get('/mail?box='.$this->second->id.'&message='.Str::uuid())->assertNotFound()
            ->assertSessionHas('mail.active_box', $this->first->id);
        $this->get('/mail?box='.$this->second->id.'&folder=invalid')->assertStatus(422)
            ->assertSessionHas('mail.active_box', $this->first->id);
    }

    public function test_draft_sender_and_mailbox_remain_fixed_when_the_same_user_switches_companies(): void
    {
        $this->post('/drafts', ['mailbox_id' => $this->first->id])->assertRedirect();
        $draft = Message::where('author_id', $this->user->id)->where('status', 'draft')->sole();
        $this->get('/mail?box='.$this->second->id)->assertOk();
        $this->post('/drafts/'.$draft->id, [
            'version' => $draft->version,
            'to' => 'recipient@example.test',
            'subject' => 'Rascunho da primeira empresa',
            'body_text' => 'O remetente deve continuar vinculado à caixa original.',
            'mailbox_id' => $this->second->id,
            'sender' => $this->second->address,
        ])->assertRedirect();

        $draft->refresh();
        $this->assertSame($this->first->id, $draft->mailbox_id);
        $this->assertSame($this->first->address, $draft->sender);
        $this->assertSame($this->user->id, $draft->author_id);
        $this->get('/mail?box='.$this->second->id.'&folder=drafts&message='.$draft->id)->assertNotFound();
        $this->get('/mail?box='.$this->first->id.'&folder=drafts&message='.$draft->id)->assertOk();
    }

    public function test_a_shared_mailbox_switch_does_not_reveal_another_authors_private_draft(): void
    {
        $author = User::factory()->create();
        $draft = $this->message($this->second, [
            'direction' => 'outbound', 'folder' => 'drafts', 'status' => 'draft',
            'author_id' => $author->id, 'subject' => 'Rascunho privado de outro membro',
        ]);
        $this->get('/mail?box='.$this->second->id.'&folder=drafts')->assertOk()->assertDontSee($draft->subject);
        $this->get('/mail?box='.$this->second->id.'&folder=drafts&message='.$draft->id)->assertNotFound();
    }

    public function test_reader_can_switch_to_a_read_only_box_but_cannot_create_reply_or_move_messages(): void
    {
        MailboxGrant::where('user_id', $this->user->id)->where('mailbox_id', $this->second->id)->update(['can_send' => false]);
        $message = $this->message($this->second);
        $response = $this->get('/mail?box='.$this->second->id.'&message='.$message->id)->assertOk()
            ->assertViewHas('canSend', false);
        $this->assertContains($this->second->id, $this->selector($response)['ids']);
        $this->post('/drafts', ['mailbox_id' => $this->second->id])->assertNotFound();
        $this->post('/drafts', ['mailbox_id' => $this->second->id, 'reply_to' => $message->id])->assertNotFound();
        $this->post('/messages/'.$message->id.'/move', ['folder' => 'trash'])->assertForbidden();
        $this->assertSame('inbox', $message->fresh()->folder);
    }

    public function test_switching_preserves_each_mailboxs_independent_search(): void
    {
        $this->withSession(['search' => [$this->first->id => 'alpha', $this->second->id => 'beta']]);
        $this->get('/mail?box='.$this->first->id)->assertOk()->assertViewHas('search', 'alpha');
        $this->get('/mail?box='.$this->second->id)->assertOk()->assertViewHas('search', 'beta');
        $this->get('/mail?box='.$this->first->id)->assertOk()->assertViewHas('search', 'alpha')
            ->assertSessionHas('search.'.$this->first->id, 'alpha')
            ->assertSessionHas('search.'.$this->second->id, 'beta');
    }

    public function test_zero_grants_shows_an_empty_mailbox_state_and_clears_stale_preference(): void
    {
        $this->withSession(['mail.active_box' => $this->first->id]);
        MailboxGrant::where('user_id', $this->user->id)->delete();
        $this->get('/mail')->assertOk()
            ->assertViewHas('box', null)
            ->assertViewHas('boxes', fn ($boxes) => $boxes->isEmpty())
            ->assertViewHas('messages', fn ($messages) => $messages->total() === 0)
            ->assertSessionMissing('mail.active_box')
            ->assertDontSee($this->first->address)->assertDontSee($this->second->address)->assertDontSee($this->foreign->address);
    }
}
