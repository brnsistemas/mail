<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
use App\Models\MailOutbox;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Attachments;
use App\Services\OutgoingMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComposeSendTest extends TestCase
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
        return Message::create(array_merge(['mailbox_id' => $this->box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received', 'subject' => 'HTML sintético', 'sender' => 'sender@example.test', 'recipients' => ['to' => [$this->box->address]], 'body_text' => 'Alternativa em texto', 'body_html' => null], $extra));
    }

    private function draft(): Message
    {
        return $this->message(['direction' => 'outbound', 'folder' => 'drafts', 'status' => 'draft', 'author_id' => $this->user->id, 'recipients' => ['to' => ['recipient@example.test'], 'cc' => [], 'bcc' => []]]);
    }

    public function test_json_save_send_queues_latest_content_once(): void
    {
        $m = $this->draft();
        $payload = ['version' => 1, 'to' => 'recipient@example.test', 'subject' => 'Última edição', 'body_text' => 'Texto final'];
        $this->postJson('/drafts/'.$m->id, $payload)->assertOk()->assertJson(['saved' => true, 'version' => 2]);
        $this->postJson('/drafts/'.$m->id.'/send', ['version' => 2])->assertOk()->assertJson(['queued' => true]);
        $this->postJson('/drafts/'.$m->id.'/send', ['version' => 2])->assertOk()->assertJson(['queued' => true]);
        $this->assertSame('Texto final', $m->fresh()->body_text);
        $this->assertSame('queued', $m->fresh()->status);
        $this->assertSame(1, MailOutbox::where('message_id', $m->id)->count());
        Http::assertNothingSent();
    }

    public function test_attachment_json_status_is_private_and_waits_for_scanner(): void
    {
        Queue::fake();
        $m = $this->draft();
        $this->postJson('/drafts/'.$m->id.'/attachments', ['attachment' => UploadedFile::fake()->createWithContent('qa.txt', 'Texto de QA')])->assertCreated()->assertJson(['uploaded' => true, 'attachment' => ['status' => 'quarantine']]);
        $response = $this->getJson('/drafts/'.$m->id.'/status')->assertOk()->assertJsonPath('attachments.0.status', 'quarantine');
        $this->assertArrayNotHasKey('path', $response->json('attachments.0'));
        $this->postJson('/drafts/'.$m->id.'/send', ['version' => 1])->assertStatus(422);
        app(Attachments::class)->scan($m->attachments()->sole());
        $this->getJson('/drafts/'.$m->id.'/status')->assertOk()->assertJsonPath('attachments.0.status', 'clean');
        $this->postJson('/drafts/'.$m->id.'/send', ['version' => 1])->assertOk();
        Http::assertNothingSent();
    }

    public function test_status_denies_other_authors_even_with_box_grant_and_revoked_access(): void
    {
        $m = $this->draft();
        $other = User::factory()->create(['totp_confirmed_at' => now(), 'master' => true]);
        Membership::create(['organization_id' => $this->box->domain->product->organization_id, 'user_id' => $other->id, 'active' => true]);
        MailboxGrant::create(['mailbox_id' => $this->box->id, 'user_id' => $other->id, 'can_read' => true, 'can_send' => true]);
        $this->actingAs($other)->getJson('/drafts/'.$m->id.'/status')->assertNotFound();
        $this->actingAs($this->user)->withSession(['mfa_version' => 0])->get('/drafts/'.$m->id.'/status')->assertRedirect('/two-factor');
        $this->withSession(['mfa_version' => 1]);
        MailboxGrant::where('user_id', $this->user->id)->update(['can_send' => false]);
        $this->getJson('/drafts/'.$m->id.'/status')->assertNotFound();
    }

    public function test_restricted_recipient_is_explained_before_queue_without_losing_draft(): void
    {
        config(['brnmail.transport' => 'resend', 'brnmail.external_enabled' => true, 'brnmail.restrict_test_recipients' => true, 'brnmail.test_recipients' => []]);
        $m = $this->draft();
        $this->postJson('/drafts/'.$m->id.'/send', ['version' => 1])->assertStatus(422)->assertJsonFragment(['message' => 'Envio em homologação: há destinatário fora da lista autorizada. O administrador precisa liberar o envio; seu rascunho foi preservado.']);
        $this->assertSame('draft', $m->fresh()->status);
        $this->assertSame(0, MailOutbox::count());
        Http::assertNothingSent();
    }

    public function test_normal_mode_keeps_external_gate_and_can_use_provider_without_test_allowlist(): void
    {
        config(['brnmail.transport' => 'resend', 'brnmail.external_enabled' => false, 'brnmail.restrict_test_recipients' => false, 'brnmail.test_recipients' => [], 'brnmail.resend_key' => 'fake-qa']);
        $m = $this->draft();
        $this->box->domain->update(['status' => 'verified']);
        $this->postJson('/drafts/'.$m->id.'/send', ['version' => 1])->assertStatus(422);
        config(['brnmail.external_enabled' => true]);
        Http::fake(['api.resend.com/emails' => Http::response(['id' => (string) Str::uuid()])]);
        $this->postJson('/drafts/'.$m->id.'/send', ['version' => 1])->assertOk();
        $outbox = MailOutbox::where('message_id', $m->id)->sole();
        app(OutgoingMail::class)->process($outbox->id);
        $this->assertSame('accepted', $m->fresh()->status);
        Http::assertSentCount(1);
    }
}
