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
use App\Models\WebhookEvent;
use App\Services\IncomingMail;
use App\Services\MailHtml;
use App\Services\RestoreMessageHtml;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class HtmlAndSpamTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Mailbox $box;

    private const HTML = '<h1>Convite de QA</h1><table><tr><td><a href="https://example.test/accept?token=SYNTHETIC" style="background-color:#175cd3;color:#fff;padding:12px 20px;border-radius:6px">Aceitar convite</a><img src="https://tracker.test/pixel"><script>attack()</script></td></tr></table>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['totp_confirmed_at' => now()]);
        $organization = Organization::create(['name' => 'QA HTML']);
        $product = Product::create(['name' => 'QA', 'organization_id' => $organization->id]);
        $domain = MailDomain::create(['product_id' => $product->id, 'domain' => 'html.test', 'status' => 'local']);
        $this->box = Mailbox::create(['mail_domain_id' => $domain->id, 'name' => 'QA', 'address' => 'qa@html.test']);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $this->user->id, 'active' => true]);
        MailboxGrant::create(['mailbox_id' => $this->box->id, 'user_id' => $this->user->id, 'can_read' => true, 'can_send' => true]);
        $this->actingAs($this->user)->withSession(['mfa_version' => 1]);
    }

    private function message(array $extra = []): Message
    {
        return Message::create(array_merge(['mailbox_id' => $this->box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received', 'subject' => 'HTML sintético', 'sender' => 'sender@example.test', 'recipients' => ['to' => [$this->box->address]], 'body_text' => 'Alternativa em texto', 'body_html' => self::HTML], $extra));
    }

    public function test_inbound_stores_sanitized_encrypted_html_without_losing_plaintext_or_idempotence(): void
    {
        $provider = (string) Str::uuid();
        $data = ['id' => $provider, 'from' => 'sender@example.test', 'to' => [$this->box->address], 'cc' => [], 'bcc' => [], 'text' => 'Texto original', 'html' => self::HTML, 'subject' => 'HTML sintético', 'attachments' => []];
        $event = WebhookEvent::create(['event_id' => 'qa_'.Str::random(20), 'type' => 'email.received', 'email_id' => $provider, 'payload' => ['data' => $data], 'body_hash' => hash('sha256', $provider)]);
        app(IncomingMail::class)->ingest($event, $data);
        app(IncomingMail::class)->ingest($event->fresh(), $data);
        $message = Message::where('provider_id', $provider)->sole();
        $this->assertSame('Texto original', $message->body_text);
        $this->assertStringContainsString('Aceitar convite', $message->body_html);
        $this->assertStringNotContainsString('tracker.test', $message->body_html);
        $this->assertStringNotContainsString('attack', $message->body_html);
        $this->assertStringNotContainsString('Aceitar convite', DB::table('messages')->where('id', $message->id)->value('body_html'));
        $this->assertArrayNotHasKey('body_html', $message->toArray());
        Http::assertNothingSent();
    }

    public function test_html_is_rendered_in_an_isolated_frame_and_resanitized_at_read_time(): void
    {
        $message = $this->message();
        $this->get('/mail?box='.$this->box->id.'&message='.$message->id)->assertOk()->assertSee('/messages/'.$message->id.'/html', false)
            ->assertSee('sandbox="'.MailHtml::SANDBOX.'"', false)->assertSee('Ver versão em texto')->assertSee('Alternativa em texto')->assertDontSee('SYNTHETIC');
        $response = $this->get('/messages/'.$message->id.'/html')->assertOk()->assertHeader('Content-Security-Policy', MailHtml::CSP)
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertSee('background-color:#175cd3', false)->assertSee('Aceitar convite')->assertDontSee('tracker.test')->assertDontSee('<script', false);
        $this->get('/mail')->assertHeader('X-Frame-Options', 'DENY');
        Http::assertNothingSent();
    }

    public function test_html_endpoint_denies_foreign_master_revoked_sensitive_and_pending_mfa_access(): void
    {
        $message = $this->message();
        $master = User::factory()->create(['master' => true, 'totp_confirmed_at' => now()]);
        $this->actingAs($master)->get('/messages/'.$message->id.'/html')->assertNotFound();
        $this->actingAs($this->user)->withSession(['mfa_version' => 0])->get('/messages/'.$message->id.'/html')->assertRedirect('/two-factor');
        $this->withSession(['mfa_version' => 1]);
        $this->box->update(['sensitive' => true]);
        $this->get('/messages/'.$message->id.'/html')->assertNotFound();
        $this->box->update(['sensitive' => false]);
        Membership::where('user_id', $this->user->id)->update(['active' => false]);
        $this->get('/messages/'.$message->id.'/html')->assertNotFound();
        auth()->logout();
        $this->get('/messages/'.$message->id.'/html')->assertRedirect('/login');
    }

    public function test_plain_only_mail_does_not_load_an_empty_frame(): void
    {
        $message = $this->message(['body_html' => null]);
        $this->get('/mail?box='.$this->box->id.'&message='.$message->id)->assertOk()->assertSee('Alternativa em texto')->assertDontSee('<iframe', false);
        $this->get('/messages/'.$message->id.'/html')->assertNotFound();
    }

    public function test_spam_moves_only_the_selected_message_and_can_return_to_inbox(): void
    {
        $message = $this->message();
        $untouched = $this->message(['subject' => 'Outra mensagem']);
        $this->post('/messages/'.$message->id.'/move', ['folder' => 'spam'])->assertRedirect();
        $this->assertSame('spam', $message->fresh()->folder);
        $this->assertSame('inbox', $untouched->fresh()->folder);
        $this->get('/mail?box='.$this->box->id.'&folder=spam&message='.$message->id)->assertOk()->assertSee('Não é spam')
            ->assertViewHas('counts', fn ($counts) => $counts['spam'] === 1)->assertViewHas('messages', fn ($messages) => $messages->total() === 1);
        $this->get('/mail?box='.$this->box->id)->assertViewHas('messages', fn ($messages) => $messages->pluck('id')->all() === [$untouched->id]);
        $this->post('/messages/'.$message->id.'/move', ['folder' => 'inbox'])->assertRedirect();
        $this->assertSame('inbox', $message->fresh()->folder);
    }

    public function test_read_only_users_cannot_classify_spam_and_other_users_cannot_read_it(): void
    {
        $message = $this->message(['folder' => 'spam']);
        MailboxGrant::where('user_id', $this->user->id)->update(['can_send' => false]);
        $this->post('/messages/'.$message->id.'/move', ['folder' => 'inbox'])->assertForbidden();
        $other = User::factory()->create(['totp_confirmed_at' => now()]);
        $this->actingAs($other)->get('/mail?box='.$this->box->id.'&folder=spam')->assertNotFound();
        $this->post('/messages/'.$message->id.'/move', ['folder' => 'inbox'])->assertNotFound();
    }

    public function test_outgoing_and_draft_messages_cannot_be_marked_as_spam(): void
    {
        foreach ([['direction' => 'outbound', 'status' => 'delivered', 'folder' => 'sent'], ['direction' => 'outbound', 'status' => 'draft', 'folder' => 'drafts', 'author_id' => $this->user->id]] as $extra) {
            $message = $this->message($extra);
            $this->post('/messages/'.$message->id.'/move', ['folder' => 'spam'])->assertStatus(409);
            $this->get('/messages/'.$message->id.'/html')->assertNotFound();
        }
    }

    public function test_restoration_is_scoped_idempotent_and_preserves_message_state(): void
    {
        config(['brnmail.transport' => 'resend', 'brnmail.external_enabled' => true, 'brnmail.resend_key' => 're_synthetic_qa']);
        $message = $this->message(['body_html' => null, 'provider_id' => (string) Str::uuid()]);
        $before = $message->fresh()->getRawOriginal();
        Http::fake(['api.resend.com/*' => Http::response(['id' => $message->provider_id, 'text' => $message->body_text, 'html' => self::HTML])]);
        $restore = app(RestoreMessageHtml::class);
        $this->assertTrue($restore->restore($this->user, $message->id));
        $this->assertFalse($restore->restore($this->user, $message->id));
        $after = $message->fresh()->getRawOriginal();
        unset($before['body_html'],$after['body_html']);
        $this->assertSame($before, $after);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('mail_audits', ['actor_id' => $this->user->id, 'resource_id' => $message->id, 'action' => 'message.html_restored']);
    }

    public function test_restoration_rejects_a_different_original_without_modification(): void
    {
        config(['brnmail.transport' => 'resend', 'brnmail.external_enabled' => true, 'brnmail.resend_key' => 're_synthetic_qa']);
        $message = $this->message(['body_html' => null, 'provider_id' => (string) Str::uuid()]);
        Http::fake(['api.resend.com/*' => Http::response(['id' => $message->provider_id, 'text' => 'Different message', 'html' => self::HTML])]);
        try {
            app(RestoreMessageHtml::class)->restore($this->user, $message->id);
            $this->fail('Restoration should reject mismatch');
        } catch (\RuntimeException $e) {
            $this->assertSame('original_message_mismatch', $e->getMessage());
        }
        $this->assertNull($message->fresh()->body_html);
    }

    public function test_restoration_rejects_a_master_without_grant_before_contacting_provider(): void
    {
        $message = $this->message(['body_html' => null, 'provider_id' => (string) Str::uuid()]);
        $master = User::factory()->create(['master' => true]);
        try {
            app(RestoreMessageHtml::class)->restore($master, $message->id);
            $this->fail('Restoration should require read permission');
        } catch (ModelNotFoundException) {
            $this->assertNull($message->fresh()->body_html);
        }
        Http::assertNothingSent();
    }
}
