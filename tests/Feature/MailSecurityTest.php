<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
use App\Models\MailInvite;
use App\Models\MailOutbox;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProviderSetting;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Access;
use App\Services\Attachments;
use App\Services\BackupArchive;
use App\Services\DomainStatus;
use App\Services\IncomingMail;
use App\Services\MessageContent;
use App\Services\Mfa;
use App\Services\OutgoingMail;
use App\Services\ReceivedAttachments;
use App\Services\ReconcileMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MailSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private User $other;

    private Mailbox $box;

    private Mailbox $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->user,$this->box] = $this->workspace('a');
        [$this->other,$this->foreign] = $this->workspace('b');
        $this->actingAs($this->user)->withSession(['mfa_version' => 1]);
    }

    private function workspace(string $prefix): array
    {
        $u = User::factory()->create(['email' => $prefix.'@example.test', 'password' => 'Local-Password-For-Tests!']);
        $u->totp_secret = (new Google2FA)->generateSecretKey(32);
        $u->totp_confirmed_at = now();
        $u->save();
        $o = Organization::create(['name' => 'Empresa '.$prefix]);
        Membership::create(['organization_id' => $o->id, 'user_id' => $u->id, 'active' => true]);
        $p = Product::create(['organization_id' => $o->id, 'name' => 'Produto '.$prefix]);
        $d = MailDomain::create(['product_id' => $p->id, 'domain' => $prefix.'.test', 'status' => 'local']);
        $box = Mailbox::create(['mail_domain_id' => $d->id, 'name' => 'Caixa '.$prefix, 'address' => 'suporte@'.$prefix.'.test']);
        MailboxGrant::create(['user_id' => $u->id, 'mailbox_id' => $box->id, 'can_read' => true, 'can_send' => true]);

        return [$u, $box];
    }

    private function msg(?Mailbox $box = null, array $extra = []): Message
    {
        $box ??= $this->box;

        return Message::create(array_merge(['mailbox_id' => $box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'inbound', 'folder' => 'inbox', 'status' => 'received', 'subject' => 'Segredo interno', 'sender' => 'Cliente <client@example.test>', 'body_text' => 'Texto confidencial para teste', 'recipients' => ['to' => [$box->address], 'cc' => [], 'bcc' => []]], $extra));
    }

    private function draft(): Message
    {
        return $this->msg(null, ['author_id' => $this->user->id, 'direction' => 'outbound', 'folder' => 'drafts', 'status' => 'draft', 'recipients' => ['to' => ['recipient@example.test'], 'cc' => [], 'bcc' => []]]);
    }

    private function scanned(Message $m, UploadedFile $file): Attachment
    {
        $s = app(Attachments::class);
        $a = $s->store($m, $file);
        $s->scan($a);

        return $a->fresh();
    }

    private function event(array $overrides = []): array
    {
        $id = (string) Str::uuid();
        $data = array_replace_recursive(['id' => $id, 'from' => 'sender@example.test', 'to' => [$this->box->address], 'cc' => [], 'bcc' => [], 'received_for' => [], 'subject' => 'Mensagem recebida', 'text' => 'Olá da integração', 'message_id' => '<test-'.$id.'@example.test>', 'attachments' => []], $overrides);
        $e = WebhookEvent::create(['event_id' => 'msg_'.Str::random(20), 'type' => 'email.received', 'email_id' => $id, 'payload' => ['type' => 'email.received', 'data' => array_merge($data, ['email_id' => $id])], 'body_hash' => hash('sha256', $id), 'available_at' => now()]);

        return [$e, $data];
    }

    public function test_guests_cannot_read_mail(): void
    {
        auth()->logout();
        $this->get('/mail')->assertRedirect('/login');
    }

    public function test_mfa_required_even_after_password(): void
    {
        $this->withSession(['mfa_version' => null])->get('/mail')->assertRedirect('/two-factor');
    }

    public function test_session_revocation_is_enforced(): void
    {
        $this->user->increment('security_version');
        $this->get('/mail')->assertRedirect('/two-factor');
    }

    public function test_valid_login_stops_at_second_factor(): void
    {
        auth()->logout();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'Local-Password-For-Tests!'])->assertRedirect('/two-factor');
        $this->get('/mail')->assertRedirect('/two-factor');
    }

    public function test_wrong_login_has_generic_error(): void
    {
        auth()->logout();
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'bad'])->assertSessionHasErrors('email');
    }

    public function test_totp_replay_is_rejected(): void
    {
        $c = (new Google2FA)->getCurrentOtp($this->user->totp_secret);
        $s = app(Mfa::class);
        $this->assertTrue($s->verify($this->user, $c));
        $this->assertFalse($s->verify($this->user, $c));
    }

    public function test_recovery_code_is_hashed_and_single_use(): void
    {
        $s = app(Mfa::class);
        $codes = $s->recovery($this->user);
        $raw = DB::table('users')->where('id', $this->user->id)->value('recovery_hashes');
        $this->assertStringNotContainsString($codes[0], $raw);
        $this->assertTrue($s->verify($this->user, $codes[0]));
        $this->assertFalse($s->verify($this->user, $codes[0]));
    }

    public function test_user_secrets_are_not_serialized(): void
    {
        $this->assertArrayNotHasKey('totp_secret', $this->user->toArray());
        $this->assertArrayNotHasKey('recovery_hashes', $this->user->toArray());
    }

    public function test_foreign_mailbox_is_not_accessible(): void
    {
        $this->get('/mail?box='.$this->foreign->id)->assertNotFound();
    }

    public function test_foreign_message_is_not_accessible(): void
    {
        $m = $this->msg($this->foreign);
        $this->get('/mail?box='.$this->box->id.'&message='.$m->id)->assertNotFound();
    }

    public function test_master_does_not_imply_content_access(): void
    {
        $this->user->master = true;
        $this->user->save();
        $this->get('/mail?box='.$this->foreign->id)->assertNotFound();
    }

    public function test_sensitive_mailbox_requires_explicit_grant(): void
    {
        $this->box->update(['sensitive' => true]);
        $this->get('/mail?box='.$this->box->id)->assertNotFound();
        MailboxGrant::where('user_id', $this->user->id)->update(['view_sensitive' => true]);
        $this->get('/mail?box='.$this->box->id)->assertOk();
    }

    public function test_removed_membership_overrides_remaining_grant(): void
    {
        Membership::where('user_id', $this->user->id)->update(['active' => false]);
        $this->get('/mail?box='.$this->box->id)->assertNotFound();
    }

    public function test_html_does_not_execute(): void
    {
        $m = $this->msg(null, ['body_text' => '<script>alert("unsafe")</script><img src="https://evil.test/x">']);
        $this->get('/mail?box='.$this->box->id.'&message='.$m->id)->assertOk()->assertDontSee('<script>alert', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_content_encrypted_in_database(): void
    {
        $m = $this->msg();
        $raw = DB::table('messages')->where('id', $m->id)->first();
        $this->assertStringNotContainsString('Segredo', $raw->subject);
        $this->assertStringNotContainsString('confidencial', $raw->body_text);
    }

    public function test_security_headers_present(): void
    {
        $this->get('/mail')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_search_is_scoped_and_not_in_url(): void
    {
        $m = $this->msg();
        app(MessageContent::class)->index($m);
        $this->post('/mail/search', ['box' => $this->box->id, 'q' => 'confidencial', 'folder' => 'inbox'])->assertRedirect('/mail?box='.$this->box->id.'&folder=inbox');
        $this->get('/mail?box='.$this->box->id)->assertSee('Segredo interno');
    }

    public function test_archive_removes_from_inbox_and_can_restore(): void
    {
        $m = $this->msg();
        $this->post('/messages/'.$m->id.'/move', ['folder' => 'archive'])->assertRedirect();
        $this->assertSame('archive', $m->fresh()->folder);
        $this->get('/mail?box='.$this->box->id)->assertDontSee('Segredo interno');
        $this->post('/messages/'.$m->id.'/move', ['folder' => 'inbox']);
        $this->get('/mail?box='.$this->box->id)->assertSee('Segredo interno');
    }

    public function test_reader_cannot_send_or_archive(): void
    {
        MailboxGrant::where('user_id', $this->user->id)->update(['can_send' => false]);
        $this->post('/drafts', ['mailbox_id' => $this->box->id])->assertNotFound();
        $m = $this->msg();
        $this->post('/messages/'.$m->id.'/move', ['folder' => 'trash'])->assertForbidden();
    }

    public function test_draft_cannot_set_foreign_sender_or_thread(): void
    {
        $parent = $this->msg($this->foreign);
        $this->post('/drafts', ['mailbox_id' => $this->box->id, 'reply_to' => $parent->id])->assertNotFound();
    }

    public function test_draft_version_conflict_preserves_text(): void
    {
        $m = $this->draft();
        $this->post('/drafts/'.$m->id, ['version' => 99, 'to' => 'client@example.test', 'subject' => 'Alterado', 'body_text' => 'Novo'])->assertStatus(409);
        $this->assertSame('Segredo interno', $m->fresh()->subject);
    }

    public function test_duplicate_send_intent_has_one_outbox(): void
    {
        $m = $this->draft();
        $s = app(OutgoingMail::class);
        $one = $s->queue($m, $this->user, 1);
        $two = $s->queue($m, $this->user, 1);
        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, MailOutbox::count());
    }

    public function test_local_send_never_calls_http(): void
    {
        $m = $this->draft();
        $s = app(OutgoingMail::class);
        $o = $s->queue($m, $this->user, 1);
        $s->process($o->id);
        $this->assertSame('simulated', $m->fresh()->status);
        Http::assertNothingSent();
        $s->process($o->id);
        $this->assertSame(1, $o->fresh()->attempts);
    }

    public function test_revoked_permission_stops_queued_send(): void
    {
        $m = $this->draft();
        $s = app(OutgoingMail::class);
        $o = $s->queue($m, $this->user, 1);
        MailboxGrant::where('user_id', $this->user->id)->update(['can_send' => false]);
        $s->process($o->id);
        $this->assertSame('access_revoked', $o->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_external_send_requires_explicit_gate(): void
    {
        config(['brnmail.transport' => 'resend']);
        $m = $this->draft();
        $this->box->domain->update(['status' => 'verified']);
        config(['brnmail.test_recipients' => ['recipient@example.test']]);
        $s = app(OutgoingMail::class);
        $o = $s->queue($m, $this->user, 1);
        $s->process($o->id);
        $this->assertSame('external_disabled', $o->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_expired_provider_idempotency_window_does_not_resend(): void
    {
        $m = $this->draft();
        $s = app(OutgoingMail::class);
        $o = $s->queue($m, $this->user, 1);
        $o->update(['first_attempt_at' => now()->subHours(25)]);
        $s->process($o->id);
        $this->assertSame('uncertain', $o->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_valid_text_attachment_is_encrypted(): void
    {
        $m = $this->draft();
        $f = UploadedFile::fake()->createWithContent('documento.txt', 'Documento de QA seguro');
        $a = $this->scanned($m, $f);
        $this->assertSame('clean', $a->status);
        $this->assertStringNotContainsString('Documento', Storage::disk('local')->get($a->path));
        $this->get('/attachments/'.$a->id)->assertOk()->assertDownload('documento.txt');
    }

    public function test_video_attachment_is_rejected(): void
    {
        $m = $this->draft();
        $this->post('/drafts/'.$m->id.'/attachments', ['attachment' => UploadedFile::fake()->create('video.mp4', 20, 'video/mp4')])->assertStatus(422);
    }

    public function test_renamed_video_is_rejected(): void
    {
        $m = $this->draft();
        $f = UploadedFile::fake()->createWithContent('picture.png', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
        $this->post('/drafts/'.$m->id.'/attachments', ['attachment' => $f])->assertStatus(422);
    }

    public function test_unavailable_scanner_blocks_send_and_download(): void
    {
        config(['brnmail.scanner' => 'disabled']);
        $m = $this->draft();
        $a = app(Attachments::class)->store($m, UploadedFile::fake()->createWithContent('documento.txt', 'Texto para conferir'));
        $this->assertSame('quarantine', $a->status);
        $this->get('/attachments/'.$a->id)->assertStatus(423);
        $this->post('/drafts/'.$m->id.'/send', ['version' => 1])->assertStatus(422);
    }

    public function test_malware_blocks_attachment(): void
    {
        $m = $this->draft();
        $a = $this->scanned($m, UploadedFile::fake()->createWithContent('teste.txt', 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE'));
        $this->assertSame('blocked', $a->status);
    }

    public function test_cross_company_attachment_is_denied(): void
    {
        $m = $this->msg($this->foreign, ['status' => 'draft']);
        $a = Attachment::create(['message_id' => $m->id, 'filename' => 'secreto.txt', 'mime' => 'text/plain', 'size' => 10, 'status' => 'clean']);
        $this->get('/attachments/'.$a->id)->assertNotFound();
        $this->post('/attachments/'.$a->id.'/remove')->assertNotFound();
    }

    public function test_more_than_five_attachments_rejected(): void
    {
        $m = $this->draft();
        for ($i = 0; $i < 5; $i++) {
            app(Attachments::class)->store($m, UploadedFile::fake()->createWithContent('file'.$i.'.txt', 'Texto teste'));
        } $this->post('/drafts/'.$m->id.'/attachments', ['attachment' => UploadedFile::fake()->createWithContent('six.txt', 'Mais um')])->assertStatus(422);
    }

    public function test_unsigned_webhook_rejected_without_rows(): void
    {
        config(['brnmail.webhook_secret' => 'whsec_'.base64_encode(random_bytes(32))]);
        $this->postJson('/webhooks/resend', ['type' => 'email.received'])->assertStatus(400);
        $this->assertSame(0, WebhookEvent::count());
    }

    private function signed(array $data, ?int $timestamp = null, ?string $id = null): array
    {
        $key = random_bytes(32);
        config(['brnmail.webhook_secret' => 'whsec_'.base64_encode($key)]);
        $time = $timestamp ?? time();
        $id ??= 'msg_'.Str::random(16);
        $raw = json_encode($data);

        return [$raw, ['CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => (string) $time, 'HTTP_SVIX_SIGNATURE' => 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$time.'.'.$raw, $key, true))]];
    }

    public function test_signed_webhook_is_durable_and_idempotent(): void
    {
        [$body,$h] = $this->signed(['type' => 'email.received', 'data' => ['email_id' => (string) Str::uuid(), 'to' => [$this->box->address]]]);
        $this->call('POST', '/webhooks/resend', [], [], [], $h, $body)->assertStatus(202);
        $this->call('POST', '/webhooks/resend', [], [], [], $h, $body)->assertStatus(202);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_webhook_ignores_unmapped_incoming_without_persistence_or_provider_reads(): void
    {
        Http::fake();
        foreach (['other@unrelated.test', 'unknown@a.test'] as $address) {
            [$body, $headers] = $this->signed(['type' => 'email.received', 'data' => ['email_id' => (string) Str::uuid(), 'to' => [$address], 'subject' => 'Not ours']]);
            $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202)->assertJson(['ignored' => true]);
        }
        $this->assertSame(0, WebhookEvent::count());
        Http::assertNothingSent();
    }

    public function test_webhook_recognizes_alias_in_bcc_and_keeps_only_processing_metadata(): void
    {
        DB::table('mail_aliases')->insert(['mailbox_id' => $this->box->id, 'address' => 'alias@a.test', 'created_at' => now(), 'updated_at' => now()]);
        [$body, $headers] = $this->signed(['type' => 'email.received', 'data' => ['email_id' => (string) Str::uuid(), 'to' => ['elsewhere@example.test'], 'bcc' => ['ALIAS@a.test'], 'subject' => 'Private subject', 'from' => 'sender@example.test']]);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
        $data = WebhookEvent::sole()->payload['data'];
        $this->assertSame(['ALIAS@a.test'], $data['bcc']);
        $this->assertArrayNotHasKey('subject', $data);
        $this->assertArrayNotHasKey('from', $data);
    }

    public function test_webhook_does_not_accept_disabled_mailbox_alias(): void
    {
        DB::table('mail_aliases')->insert(['mailbox_id' => $this->box->id, 'address' => 'alias@a.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->box->update(['active' => false]);
        [$body, $headers] = $this->signed(['type' => 'email.received', 'data' => ['email_id' => (string) Str::uuid(), 'to' => [$this->box->address, 'alias@a.test']]]);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202)->assertJson(['ignored' => true]);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_webhook_rejects_malformed_recipients_before_persistence(): void
    {
        foreach (['string-instead-of-array', ['not-an-email'], [['nested' => 'a@a.test']]] as $to) {
            [$body, $headers] = $this->signed(['type' => 'email.received', 'data' => ['email_id' => (string) Str::uuid(), 'to' => $to]]);
            $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(422);
        }
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_shared_sender_does_not_make_an_unrelated_delivery_ours(): void
    {
        [$body, $headers] = $this->signed(['type' => 'email.delivered', 'data' => ['email_id' => (string) Str::uuid(), 'from' => $this->box->address, 'to' => ['client@example.test']]]);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202)->assertJson(['ignored' => true]);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_webhook_accepts_known_outbound_and_not_an_inbound_provider_id(): void
    {
        $id = (string) Str::uuid();
        $message = $this->msg(null, ['provider_id' => $id]);
        $event = ['type' => 'email.delivered', 'data' => ['email_id' => $id]];
        [$body, $headers] = $this->signed($event);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202)->assertJson(['ignored' => true]);
        $this->assertSame(0, WebhookEvent::count());
        $message->update(['direction' => 'outbound', 'status' => 'accepted']);
        [$body, $headers] = $this->signed($event);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
        app(IncomingMail::class)->process(WebhookEvent::sole()->id);
        $this->assertSame('delivered', $message->fresh()->status);
    }

    public function test_delivery_before_send_response_is_correlated_with_our_attempt(): void
    {
        $this->box->domain->update(['status' => 'verified']);
        config(['brnmail.external_enabled' => true, 'brnmail.transport' => 'resend', 'brnmail.resend_key' => 'fake-key', 'brnmail.test_recipients' => ['recipient@example.test']]);
        $draft = $this->draft();
        $outbox = app(OutgoingMail::class)->queue($draft, $this->user, 1);
        $providerId = (string) Str::uuid();
        Http::fake(['api.resend.com/emails' => function ($request) use ($outbox, $providerId) {
            $this->assertSame([['name' => 'brnmail_outbox', 'value' => $outbox->id]], $request['tags']);
            $this->assertNull($outbox->message->provider_id);
            [$body, $headers] = $this->signed(['type' => 'email.delivered', 'data' => ['email_id' => $providerId, 'from' => $this->box->address, 'tags' => ['brnmail_outbox' => $outbox->id]]]);
            $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
            app(IncomingMail::class)->process(WebhookEvent::sole()->id);
            $this->assertSame('awaiting_send_result', WebhookEvent::sole()->last_error);

            return Http::response(['id' => $providerId]);
        }]);
        app(OutgoingMail::class)->process($outbox->id);
        $this->assertSame('accepted', $draft->fresh()->status);
        WebhookEvent::sole()->update(['available_at' => now()]);
        app(IncomingMail::class)->process(WebhookEvent::sole()->id);
        $this->assertSame('delivered', $draft->fresh()->status);
    }

    public function test_outbox_tag_requires_attempt_sender_and_matching_provider_id(): void
    {
        $draft = $this->draft();
        $outbox = app(OutgoingMail::class)->queue($draft, $this->user, 1);
        $providerId = (string) Str::uuid();
        $data = ['email_id' => $providerId, 'from' => $this->box->address, 'tags' => ['brnmail_outbox' => $outbox->id]];
        $checkIgnored = function (array $data): void {
            [$body, $headers] = $this->signed(['type' => 'email.sent', 'data' => $data]);
            $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202)->assertJson(['ignored' => true]);
            $this->assertSame(0, WebhookEvent::count());
        };
        $checkIgnored($data);
        $outbox->update(['first_attempt_at' => now()]);
        $checkIgnored(array_replace($data, ['from' => 'other@a.test']));
        $checkIgnored(array_replace($data, ['tags' => ['brnmail_outbox' => (string) Str::uuid()]]));
        $draft->update(['provider_id' => (string) Str::uuid()]);
        $checkIgnored($data);
    }

    public function test_webhook_duplicate_keeps_hash_check_after_mailbox_is_disabled(): void
    {
        $eventId = 'msg_'.Str::random(16);
        $data = ['type' => 'email.received', 'data' => ['email_id' => (string) Str::uuid(), 'to' => [$this->box->address]]];
        [$body, $headers] = $this->signed($data, null, $eventId);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
        $this->box->update(['active' => false]);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
        $data['data']['to'] = ['different@unrelated.test'];
        [$body, $headers] = $this->signed($data, null, $eventId);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(409);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_changed_webhook_body_and_old_signature_rejected(): void
    {
        [$body,$h] = $this->signed(['type' => 'email.received', 'data' => ['email_id' => (string) Str::uuid()]]);
        $this->call('POST', '/webhooks/resend', [], [], [], $h, $body.' ')->assertStatus(400);
    }

    public function test_old_webhook_timestamp_rejected(): void
    {
        [$body,$h] = $this->signed(['type' => 'email.received', 'data' => ['email_id' => (string) Str::uuid()]], time() - 600);
        $this->call('POST', '/webhooks/resend', [], [], [], $h, $body)->assertStatus(400);
    }

    public function test_ingestion_deduplicates_and_hides_bcc(): void
    {
        [$e,$data] = $this->event(['bcc' => ['private@example.test']]);
        $s = app(IncomingMail::class);
        $s->ingest($e, $data);
        $s->ingest($e, $data);
        $this->assertSame(1, Message::count());
        $this->assertSame([], Message::first()->recipients['bcc']);
    }

    public function test_unknown_recipient_never_creates_mailbox(): void
    {
        [$e,$data] = $this->event(['to' => ['unknown@a.test']]);
        $this->expectExceptionMessage('recipient_unknown');
        app(IncomingMail::class)->ingest($e, $data);
    }

    public function test_received_for_is_not_used_as_authorization(): void
    {
        [$e,$data] = $this->event(['received_for' => [$this->foreign->address]]);
        $this->expectExceptionMessage('forwarded_delivery_needs_review');
        app(IncomingMail::class)->ingest($e, $data);
    }

    public function test_direct_received_for_matching_the_recipient_is_received_once(): void
    {
        [$e, $data] = $this->event(['received_for' => [$this->box->address]]);
        $s = app(IncomingMail::class);
        $s->ingest($e, $data);
        $s->ingest($e, $data);
        $this->assertSame('done', $e->fresh()->status);
        $this->assertSame(1, Message::count());
        $this->assertSame($this->box->id, Message::sole()->mailbox_id);
        $this->assertSame('received', Message::sole()->status);
    }

    public function test_matching_received_for_does_not_bypass_signed_recipient_check(): void
    {
        [$e, $data] = $this->event(['received_for' => [$this->box->address]]);
        $e->update(['payload' => ['type' => 'email.received', 'data' => ['to' => [$this->foreign->address]]]]);
        $this->expectExceptionMessage('ambiguous_recipient');
        app(IncomingMail::class)->ingest($e, $data);
    }

    public function test_matching_received_for_does_not_bypass_company_isolation(): void
    {
        [$e, $data] = $this->event(['to' => [$this->box->address, $this->foreign->address], 'received_for' => [$this->box->address, $this->foreign->address]]);
        $this->expectExceptionMessage('cross_company_delivery');
        app(IncomingMail::class)->ingest($e, $data);
    }

    public function test_received_for_does_not_add_an_unknown_recipient(): void
    {
        [$e, $data] = $this->event(['received_for' => [$this->box->address, 'unknown@a.test']]);
        $this->expectExceptionMessage('forwarded_delivery_needs_review');
        app(IncomingMail::class)->ingest($e, $data);
    }

    public function test_malformed_received_for_is_quarantined(): void
    {
        [$e, $data] = $this->event(['received_for' => 'not-an-array']);
        $this->expectExceptionMessage('forwarded_delivery_needs_review');
        app(IncomingMail::class)->ingest($e, $data);
    }

    public function test_alias_received_for_is_normalized_and_does_not_duplicate_the_box(): void
    {
        DB::table('mail_aliases')->insert(['mailbox_id' => $this->box->id, 'address' => 'alias@a.test', 'created_at' => now(), 'updated_at' => now()]);
        [$e, $data] = $this->event(['to' => ['alias@a.test', $this->box->address], 'received_for' => ['ALIAS@A.TEST', $this->box->address]]);
        app(IncomingMail::class)->ingest($e, $data);
        $this->assertSame(1, Message::count());
        $this->assertSame($this->box->id, Message::sole()->mailbox_id);
        $this->assertSame(['to' => [$this->box->address], 'cc' => [], 'bcc' => []], Message::sole()->recipients);
    }

    public function test_cross_company_delivery_is_quarantined(): void
    {
        [$e,$data] = $this->event(['to' => [$this->box->address, $this->foreign->address]]);
        $this->expectExceptionMessage('cross_company_delivery');
        app(IncomingMail::class)->ingest($e, $data);
    }

    public function test_incoming_video_preserves_body_and_blocks_attachment(): void
    {
        [$e,$data] = $this->event(['attachments' => [['id' => (string) Str::uuid(), 'filename' => 'clip.mp4', 'content_type' => 'video/mp4', 'size' => 100]]]);
        app(IncomingMail::class)->ingest($e, $data);
        $this->assertSame('Olá da integração', Message::first()->body_text);
        $this->assertSame('blocked', Attachment::first()->status);
    }

    public function test_incoming_html_drops_scripts_images_and_styles(): void
    {
        $text = app(MessageContent::class)->text(null, '<p>Olá</p><script>secretAttack()</script><img src="https://evil.test/x"><style>body{display:none}</style>');
        $this->assertStringContainsString('Olá', $text);
        $this->assertStringNotContainsString('secretAttack', $text);
        $this->assertStringNotContainsString('evil.test', $text);
    }

    public function test_nonmaster_cannot_manage_users(): void
    {
        $this->get('/admin')->assertForbidden();
        $this->post('/admin/grants', [])->assertForbidden();
    }

    public function test_even_master_cannot_grant_cross_company_membership(): void
    {
        $this->user->master = true;
        $this->user->save();
        $this->post('/admin/grants', ['user_id' => $this->user->id, 'mailbox_id' => $this->foreign->id, 'can_read' => 1, 'password' => 'Local-Password-For-Tests!'])->assertStatus(422);
    }

    public function test_batch_grant_updates_only_selected_boxes_and_requires_mfa_once(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $normal = Mailbox::create(['mail_domain_id' => $this->box->mail_domain_id, 'name' => 'Financeiro', 'address' => 'financeiro@a.test']);
        $sensitive = Mailbox::create(['mail_domain_id' => $this->box->mail_domain_id, 'name' => 'Privacidade', 'address' => 'privacidade@a.test', 'sensitive' => true]);
        $untouched = MailboxGrant::where('user_id', $this->user->id)->where('mailbox_id', $this->box->id)->first()->getAttributes();
        $this->post('/admin/grants', [
            'user_id' => $this->user->id, 'mailbox_ids' => [$normal->id, $sensitive->id],
            'can_read' => 1, 'can_send' => 1, 'view_sensitive' => 1, 'can_manage' => 1,
            'password' => 'Local-Password-For-Tests!',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertSame($untouched, MailboxGrant::find($untouched['id'])->getAttributes());
        $this->assertSame(2, $this->user->fresh()->security_version);
        $this->assertSame(1, $this->other->fresh()->security_version);
        $this->assertSame(2, DB::table('mail_audits')->where('action', 'grant.updated')->where('actor_id', $this->user->id)->count());
        foreach ([$normal, $sensitive] as $box) {
            $this->assertTrue(app(Access::class)->allowed($this->user->fresh(), $box->id, 'read'));
            $this->assertTrue(app(Access::class)->allowed($this->user->fresh(), $box->id, 'send'));
            $this->assertFalse(app(Access::class)->allowed($this->other, $box->id));
            $this->assertDatabaseHas('mailbox_grants', ['user_id' => $this->user->id, 'mailbox_id' => $box->id, 'can_manage' => false, 'view_sensitive' => (bool) $box->sensitive]);
        }
        $this->actingAs($this->user->fresh())->get('/mail')->assertRedirect('/two-factor');
        $this->withSession(['mfa_version' => 2])->get('/mail')->assertOk()->assertSee($normal->address)->assertSee($sensitive->address);
    }

    public function test_batch_grant_without_sensitive_permission_does_not_expose_sensitive_box(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $this->box->update(['sensitive' => true]);
        $this->post('/admin/grants', [
            'user_id' => $this->user->id, 'mailbox_ids' => [$this->box->id],
            'can_read' => 1, 'can_send' => 1, 'password' => 'Local-Password-For-Tests!',
        ])->assertRedirect();
        $this->assertFalse(app(Access::class)->allowed($this->user->fresh(), $this->box->id, 'read'));
        $this->assertFalse(app(Access::class)->allowed($this->user->fresh(), $this->box->id, 'send'));
    }

    public function test_batch_grant_rejects_foreign_company_without_partial_changes(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $before = MailboxGrant::orderBy('id')->get()->toArray();
        $this->post('/admin/grants', [
            'user_id' => $this->user->id, 'mailbox_ids' => [$this->box->id, $this->foreign->id],
            'can_read' => 1, 'password' => 'Local-Password-For-Tests!',
        ])->assertStatus(422);
        $this->assertSame($before, MailboxGrant::orderBy('id')->get()->toArray());
        $this->assertSame(1, $this->user->fresh()->security_version);
        $this->assertDatabaseMissing('mail_audits', ['action' => 'grant.updated']);
    }

    public function test_batch_grant_rejects_inactive_membership(): void
    {
        $this->user->forceFill(['master' => true])->save();
        Membership::where('user_id', $this->user->id)->update(['active' => false]);
        $this->post('/admin/grants', [
            'user_id' => $this->user->id, 'mailbox_ids' => [$this->box->id],
            'can_read' => 1, 'password' => 'Local-Password-For-Tests!',
        ])->assertStatus(422);
        $this->assertSame(1, $this->user->fresh()->security_version);
        $this->assertDatabaseMissing('mail_audits', ['action' => 'grant.updated']);
    }

    public function test_batch_grant_rejects_invalid_selection_and_wrong_password_without_changes(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $before = MailboxGrant::orderBy('id')->get()->toArray();
        foreach ([
            [], ['mailbox_ids' => []], ['mailbox_ids' => [$this->box->id, (string) $this->box->id]],
            ['mailbox_ids' => [$this->box->id], 'mailbox_id' => $this->box->id],
            ['mailbox_ids' => [$this->box->id, 999999]],
            ['mailbox_ids' => array_fill(0, 51, $this->box->id)],
            ['mailbox_ids' => [$this->box->id], 'password' => 'incorrect-password'],
        ] as $payload) {
            $this->postJson('/admin/grants', array_merge([
                'user_id' => $this->user->id, 'can_read' => 1, 'password' => 'Local-Password-For-Tests!',
            ], $payload))->assertStatus(422);
        }
        $this->assertSame($before, MailboxGrant::orderBy('id')->get()->toArray());
        $this->assertSame(1, $this->user->fresh()->security_version);
        $this->assertDatabaseMissing('mail_audits', ['action' => 'grant.updated']);
    }

    public function test_batch_grant_still_requires_master_and_current_mfa(): void
    {
        $payload = ['user_id' => $this->user->id, 'mailbox_ids' => [$this->box->id], 'can_read' => 1, 'password' => 'Local-Password-For-Tests!'];
        $this->post('/admin/grants', $payload)->assertForbidden();
        $this->user->forceFill(['master' => true])->save();
        $this->withSession(['mfa_version' => 0])->post('/admin/grants', $payload)->assertRedirect('/two-factor');
        $this->assertSame(1, $this->user->fresh()->security_version);
        $this->assertDatabaseMissing('mail_audits', ['action' => 'grant.updated']);
    }

    public function test_single_mailbox_grant_remains_compatible_and_replaces_selected_permissions(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $this->post('/admin/grants', [
            'user_id' => $this->user->id, 'mailbox_id' => $this->box->id,
            'can_read' => 1, 'password' => 'Local-Password-For-Tests!',
        ])->assertRedirect();
        $this->assertTrue(app(Access::class)->allowed($this->user->fresh(), $this->box->id, 'read'));
        $this->assertFalse(app(Access::class)->allowed($this->user->fresh(), $this->box->id, 'send'));
        $this->assertSame(2, $this->user->fresh()->security_version);
        $this->assertSame(1, DB::table('mail_audits')->where('action', 'grant.updated')->count());
    }

    public function test_explicit_membership_grant_reuses_identity_and_only_links_selected_company(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $identity = collect($this->user->fresh()->getAttributes())->except(['security_version', 'updated_at'])->all();
        $foreignOrg = $this->foreign->domain->product->organization_id;
        $ownOrg = $this->box->domain->product->organization_id;
        $second = Mailbox::create(['mail_domain_id' => $this->foreign->mail_domain_id, 'name' => 'Admin', 'address' => 'admin@b.test']);
        $selectedInvite = MailInvite::create(['organization_id' => $foreignOrg, 'email' => $this->user->email, 'token_hash' => hash('sha256', 'selected-synthetic-token'), 'expires_at' => now()->addDay()]);
        $otherInvite = MailInvite::create(['organization_id' => $ownOrg, 'email' => $this->user->email, 'token_hash' => hash('sha256', 'other-synthetic-token'), 'expires_at' => now()->addDay()]);
        $otherPersonInvite = MailInvite::create(['organization_id' => $foreignOrg, 'email' => $this->other->email, 'token_hash' => hash('sha256', 'other-person-synthetic-token'), 'expires_at' => now()->addDay()]);
        $originalGrants = MailboxGrant::orderBy('id')->get()->keyBy('id')->toArray();
        $this->post('/admin/grants', [
            'user_id' => $this->user->id, 'mailbox_ids' => [$this->foreign->id, $second->id],
            'add_membership' => 1, 'can_read' => 1, 'can_send' => 1, 'password' => 'Local-Password-For-Tests!',
        ])->assertRedirect();
        $this->assertSame(2, User::count());
        $this->assertSame($identity, collect($this->user->fresh()->getAttributes())->except(['security_version', 'updated_at'])->all());
        $this->assertSame(2, $this->user->fresh()->security_version);
        $this->assertDatabaseHas('memberships', ['organization_id' => $foreignOrg, 'user_id' => $this->user->id, 'active' => true, 'role' => 'member']);
        foreach ([$this->foreign, $second] as $box) {
            $this->assertTrue(app(Access::class)->allowed($this->user->fresh(), $box->id, 'read'));
            $this->assertTrue(app(Access::class)->allowed($this->user->fresh(), $box->id, 'send'));
        }
        $this->assertSame($originalGrants, MailboxGrant::whereIn('id', array_keys($originalGrants))->orderBy('id')->get()->keyBy('id')->toArray());
        $this->assertTrue($selectedInvite->fresh()->expires_at->isPast());
        $this->assertNull($selectedInvite->fresh()->accepted_at);
        $this->assertTrue($otherInvite->fresh()->expires_at->isFuture());
        $this->assertTrue($otherPersonInvite->fresh()->expires_at->isFuture());
        $this->assertSame(1, DB::table('mail_audits')->where('action', 'membership.granted')->count());
        $this->assertSame(2, DB::table('mail_audits')->where('action', 'grant.updated')->count());
        $this->actingAs($this->user->fresh())->get('/mail')->assertRedirect('/two-factor');
        $this->withSession(['mfa_version' => 2])->get('/mail')->assertOk()->assertSee($second->address);
    }

    public function test_explicit_membership_grant_never_reactivates_revoked_access_or_partially_writes(): void
    {
        $this->user->forceFill(['master' => true])->save();
        Membership::where('user_id', $this->user->id)->update(['active' => false]);
        $before = MailboxGrant::orderBy('id')->get()->toArray();
        $this->post('/admin/grants', [
            'user_id' => $this->user->id, 'mailbox_ids' => [$this->foreign->id, $this->box->id],
            'add_membership' => 1, 'can_read' => 1, 'password' => 'Local-Password-For-Tests!',
        ])->assertStatus(422);
        $this->assertSame($before, MailboxGrant::orderBy('id')->get()->toArray());
        $this->assertDatabaseMissing('memberships', ['organization_id' => $this->foreign->domain->product->organization_id, 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('memberships', ['user_id' => $this->user->id, 'active' => false]);
        $this->assertSame(1, $this->user->fresh()->security_version);
        $this->assertDatabaseMissing('mail_audits', ['action' => 'membership.granted']);
    }

    public function test_explicit_membership_grant_requires_password_master_and_current_mfa(): void
    {
        $payload = ['user_id' => $this->user->id, 'mailbox_ids' => [$this->foreign->id], 'add_membership' => 1, 'can_read' => 1, 'password' => 'Local-Password-For-Tests!'];
        $this->post('/admin/grants', $payload)->assertForbidden();
        $this->user->forceFill(['master' => true])->save();
        $this->withSession(['mfa_version' => 0])->post('/admin/grants', $payload)->assertRedirect('/two-factor');
        $this->withSession(['mfa_version' => 1])->post('/admin/grants', array_replace($payload, ['password' => 'wrong-password']))->assertStatus(422);
        $this->postJson('/admin/grants', array_replace($payload, ['add_membership' => 'unrecognized']))->assertStatus(422);
        $this->assertDatabaseMissing('memberships', ['organization_id' => $this->foreign->domain->product->organization_id, 'user_id' => $this->user->id]);
        $this->assertDatabaseMissing('mail_audits', ['action' => 'membership.granted']);
        $this->assertSame(1, $this->user->fresh()->security_version);
    }

    public function test_explicit_membership_grant_cannot_link_an_inactive_identity(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $this->other->forceFill(['active' => false])->save();
        $this->post('/admin/grants', [
            'user_id' => $this->other->id, 'mailbox_ids' => [$this->box->id],
            'add_membership' => 1, 'can_read' => 1, 'password' => 'Local-Password-For-Tests!',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('memberships', ['organization_id' => $this->box->domain->product->organization_id, 'user_id' => $this->other->id]);
        $this->assertFalse($this->other->fresh()->active);
        $this->assertSame(1, $this->other->fresh()->security_version);
    }

    public function test_explicit_membership_grant_keeps_sensitive_permission_separate(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $this->foreign->update(['sensitive' => true]);
        $this->post('/admin/grants', [
            'user_id' => $this->user->id, 'mailbox_ids' => [$this->foreign->id],
            'add_membership' => 1, 'can_read' => 1, 'can_send' => 1, 'password' => 'Local-Password-For-Tests!',
        ])->assertRedirect();
        $this->assertDatabaseHas('memberships', ['organization_id' => $this->foreign->domain->product->organization_id, 'user_id' => $this->user->id, 'active' => true]);
        $this->assertFalse(app(Access::class)->allowed($this->user->fresh(), $this->foreign->id, 'read'));
        $this->assertFalse(app(Access::class)->allowed($this->user->fresh(), $this->foreign->id, 'send'));
    }

    public function test_queued_draft_cannot_be_changed(): void
    {
        $m = $this->draft();
        app(OutgoingMail::class)->queue($m, $this->user, 1);
        $this->post('/drafts/'.$m->id, ['version' => 2, 'to' => 'client@example.test', 'subject' => 'Changed', 'body_text' => 'New'])->assertStatus(409);
    }

    public function test_pending_attachment_does_not_show_download_link(): void
    {
        $m = $this->msg();
        $a = Attachment::create(['message_id' => $m->id, 'filename' => 'blocked.txt', 'mime' => 'text/plain', 'size' => 4, 'status' => 'blocked']);
        $this->get('/mail?box='.$this->box->id.'&message='.$m->id)->assertOk()->assertDontSee('/attachments/'.$a->id, false);
    }

    public function test_upload_is_quarantined_before_scan(): void
    {
        $a = app(Attachments::class)->store($this->draft(), UploadedFile::fake()->createWithContent('doc.txt', 'Arquivo de teste'));
        $this->assertSame('quarantine', $a->status);
    }

    public function test_private_or_arbitrary_attachment_url_rejected(): void
    {
        $this->expectExceptionMessage('attachment_download_host_rejected');
        app(ReceivedAttachments::class)->download('http://127.0.0.1/private');
    }

    public function test_cloudfront_url_with_credentials_rejected(): void
    {
        $this->expectExceptionMessage('attachment_download_host_rejected');
        app(ReceivedAttachments::class)->download('https://user:pass@d123.cloudfront.net/file');
    }

    public function test_simulator_refuses_production_environment(): void
    {
        $m = $this->draft();
        $o = app(OutgoingMail::class)->queue($m, $this->user, 1);
        $this->app->detectEnvironment(fn () => 'production');
        app(OutgoingMail::class)->process($o->id);
        $this->assertSame('local_transport_forbidden', $o->fresh()->last_error);
    }

    public function test_resend_request_uses_idempotency_key_and_correct_sender(): void
    {
        config(['brnmail.external_enabled' => true, 'brnmail.transport' => 'resend', 'brnmail.resend_key' => 'fake-key-not-real', 'brnmail.test_recipients' => ['recipient@example.test']]);
        $this->box->domain->update(['status' => 'verified']);
        $m = $this->draft();
        $id = (string) Str::uuid();
        Http::fake(['api.resend.com/emails' => Http::response(['id' => $id])]);
        $o = app(OutgoingMail::class)->queue($m, $this->user, 1);
        app(OutgoingMail::class)->process($o->id);
        $this->assertSame('accepted', $m->fresh()->status);
        $this->assertSame($id, $m->fresh()->provider_id);
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', 'brnmail-'.$o->id) && $r['from'] === $this->box->address && $r['to'] === ['recipient@example.test']);
    }

    public function test_resend_timeout_retry_retains_same_intent(): void
    {
        config(['brnmail.external_enabled' => true, 'brnmail.transport' => 'resend', 'brnmail.resend_key' => 'fake-key', 'brnmail.test_recipients' => ['recipient@example.test']]);
        $this->box->domain->update(['status' => 'verified']);
        Http::fake(['api.resend.com/emails' => Http::response([], 503)]);
        $m = $this->draft();
        $o = app(OutgoingMail::class)->queue($m, $this->user, 1);
        app(OutgoingMail::class)->process($o->id);
        $this->assertSame('retry', $o->fresh()->status);
        $this->assertSame('queued', $m->fresh()->status);
        $this->assertSame(1, MailOutbox::count());
    }

    public function test_unsanctioned_recipient_never_reaches_resend(): void
    {
        config(['brnmail.external_enabled' => true, 'brnmail.transport' => 'resend', 'brnmail.resend_key' => 'fake-key', 'brnmail.test_recipients' => []]);
        $this->box->domain->update(['status' => 'verified']);
        $m = $this->draft();
        $o = app(OutgoingMail::class)->queue($m, $this->user, 1);
        app(OutgoingMail::class)->process($o->id);
        $this->assertSame('recipient_not_approved', $o->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_out_of_order_sent_does_not_erase_bounce(): void
    {
        $id = (string) Str::uuid();
        $m = $this->msg(null, ['direction' => 'outbound', 'status' => 'accepted', 'provider_id' => $id]);
        foreach (['email.bounced', 'email.sent'] as $type) {
            $e = WebhookEvent::create(['event_id' => 'msg_'.Str::random(20), 'type' => $type, 'email_id' => $id, 'body_hash' => hash('sha256', $type), 'payload' => ['type' => $type, 'data' => ['email_id' => $id]], 'available_at' => now()]);
            app(IncomingMail::class)->process($e->id);
        }
        $this->assertSame('bounced', $m->fresh()->status);
        $this->assertSame(1, DB::table('mail_suppressions')->count());
    }

    public function test_resend_inbound_contract_uses_receiving_endpoint(): void
    {
        [$event,$data] = $this->event();
        config(['brnmail.external_enabled' => true, 'brnmail.transport' => 'resend', 'brnmail.resend_key' => 'fake-key']);
        Http::fake(['api.resend.com/emails/receiving/*' => Http::response($data)]);
        app(IncomingMail::class)->process($event->id);
        $this->assertSame('done', $event->fresh()->status);
        $this->assertSame(1, Message::count());
    }

    public function test_backup_is_encrypted_verified_and_restorable(): void
    {
        $m = $this->draft();
        $a = $this->scanned($m, UploadedFile::fake()->createWithContent('doc.txt', 'Conteudo de restore'));
        $key = random_bytes(32);
        $service = app(BackupArchive::class);
        $archive = $service->export($key);
        $this->assertStringNotContainsString('Conteudo', $archive);
        $manifest = $service->inspect($archive, $key);
        $this->assertCount(1, $manifest['tables']['messages']);
        foreach (array_reverse(BackupArchive::TABLES) as $table) {
            DB::table($table)->delete();
        } Storage::disk('local')->delete($a->path);
        $counts = $service->restore($archive, $key);
        $this->assertSame(1, $counts['messages']);
        $this->assertSame($m->body_text, Message::first()->body_text);
        $this->assertTrue(app(Access::class)->allowed(User::find($this->user->id), $this->box->id));
        $this->assertSame($a->sha256, hash('sha256', Crypt::decryptString(Storage::disk('local')->get($a->path))));
    }

    public function test_tampered_backup_rejected(): void
    {
        $service = app(BackupArchive::class);
        $key = random_bytes(32);
        $a = $service->export($key);
        $this->expectExceptionMessage('backup_integrity_failed');
        $service->inspect(substr($a, 0, -10).'BAD', $key);
    }

    public function test_daily_quota_counts_today_send_intents_not_draft_age(): void
    {
        config(['brnmail.daily_limit' => 1]);
        $a = $this->draft();
        $a->update(['created_at' => now()->subDays(10)]);
        app(OutgoingMail::class)->queue($a, $this->user, 1);
        $b = $this->draft();
        $b->update(['created_at' => now()->subDays(12)]);
        $this->post('/drafts/'.$b->id.'/send', ['version' => 1])->assertStatus(429);
        $this->assertSame(1, MailOutbox::count());
    }

    public function test_shared_mailbox_draft_is_private_on_every_route(): void
    {
        $draft = $this->draft();
        Membership::create(['user_id' => $this->other->id, 'organization_id' => $this->box->domain->product->organization_id, 'active' => true]);
        MailboxGrant::create(['user_id' => $this->other->id, 'mailbox_id' => $this->box->id, 'can_read' => true, 'can_send' => true]);
        $this->actingAs($this->other)->withSession(['mfa_version' => 1]);
        $this->get('/mail?box='.$this->box->id.'&message='.$draft->id)->assertNotFound();
        $this->post('/drafts', ['mailbox_id' => $this->box->id, 'reply_to' => $draft->id])->assertNotFound();
        $this->post('/messages/'.$draft->id.'/read')->assertNotFound();
        $this->post('/drafts/'.$draft->id.'/send', ['version' => 1])->assertNotFound();
    }

    public function test_reply_double_click_reuses_draft_and_claims_responsibility(): void
    {
        $parent = $this->msg();
        for ($i = 0; $i < 2; $i++) {
            $this->post('/drafts', ['mailbox_id' => $this->box->id, 'reply_to' => $parent->id])->assertRedirect();
        }
        $this->assertSame(1, Message::where('reply_source_id', $parent->id)->count());
        $this->assertSame($this->user->id, $parent->fresh()->assigned_to);
    }

    public function test_reply_cannot_take_another_responsible_person_conversation(): void
    {
        $parent = $this->msg(null, ['assigned_to' => $this->other->id]);
        $this->post('/drafts', ['mailbox_id' => $this->box->id, 'reply_to' => $parent->id])->assertStatus(409);
    }

    public function test_assignment_never_grants_foreign_mailbox_access(): void
    {
        $parent = $this->msg();
        $this->post('/messages/'.$parent->id.'/assign', ['user_id' => $this->other->id, 'version' => 1])->assertStatus(422);
        $this->assertNull($parent->fresh()->assigned_to);
    }

    public function test_assignment_is_versioned_and_stale_edits_do_not_overwrite(): void
    {
        $parent = $this->msg();
        $this->post('/messages/'.$parent->id.'/assign', ['user_id' => $this->user->id, 'version' => 1])->assertRedirect();
        $this->post('/messages/'.$parent->id.'/assign', ['user_id' => null, 'version' => 1])->assertStatus(409);
        $this->assertSame($this->user->id, $parent->fresh()->assigned_to);
    }

    public function test_every_attachment_descriptor_is_recorded_even_beyond_limit(): void
    {
        $files = array_map(fn ($n) => ['id' => (string) Str::uuid(), 'filename' => 'arquivo'.$n.'.txt', 'content_type' => 'text/plain', 'size' => 20], range(1, 7));
        [$event, $data] = $this->event(['attachments' => $files]);
        app(IncomingMail::class)->ingest($event, $data);
        $this->assertSame(7, Attachment::count());
        $this->assertSame(2, Attachment::where('status', 'blocked')->count());
        $this->assertSame('Olá da integração', Message::first()->body_text);
    }

    public function test_incoming_total_size_reserves_only_allowed_files(): void
    {
        $files = array_map(fn ($n) => ['id' => (string) Str::uuid(), 'filename' => 'arquivo'.$n.'.pdf', 'content_type' => 'application/pdf', 'size' => 9 * 1024 * 1024], range(1, 3));
        [$event, $data] = $this->event(['attachments' => $files]);
        app(IncomingMail::class)->ingest($event, $data);
        $this->assertSame(2, Attachment::where('status', 'unavailable')->count());
        $this->assertSame(1, Attachment::where('reason', 'attachment_limits_exceeded')->count());
    }

    public function test_large_body_is_rejected_instead_of_silently_truncated(): void
    {
        $this->expectExceptionMessage('message_body_limit');
        app(MessageContent::class)->text(str_repeat('a', 200001), null);
    }

    public function test_alias_cannot_use_foreign_domain_or_existing_address(): void
    {
        $this->user->forceFill(['master' => true])->save();
        foreach (['alias@b.test', $this->box->address] as $address) {
            $this->post('/admin/aliases', ['mailbox_id' => $this->box->id, 'address' => $address, 'password' => 'Local-Password-For-Tests!'])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('mail_aliases')->count());
    }

    public function test_alias_delivers_only_to_assigned_box(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $this->post('/admin/aliases', ['mailbox_id' => $this->box->id, 'address' => 'alias@a.test', 'password' => 'Local-Password-For-Tests!'])->assertRedirect();
        [$event, $data] = $this->event(['to' => ['alias@a.test']]);
        app(IncomingMail::class)->ingest($event, $data);
        $this->assertSame($this->box->id, Message::first()->mailbox_id);
    }

    public function test_provider_vault_is_encrypted_and_cannot_enable_external_send(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $fake = 're_only_a_local_fake_key_for_tests';
        $this->post('/admin/provider', ['api_key' => $fake, 'test_recipients' => 'test@example.test', 'password' => 'Local-Password-For-Tests!'])->assertRedirect();
        $this->assertStringNotContainsString($fake, DB::table('provider_settings')->value('api_key'));
        $this->get('/admin')->assertDontSee($fake);
        $this->assertFalse(config('brnmail.external_enabled'));
        $this->assertSame($fake, ProviderSetting::valueFor('api_key'));
    }

    public function test_provider_vault_uses_its_explicit_id_after_another_row_existed(): void
    {
        $this->user->forceFill(['master' => true])->save();
        $unrelated = new ProviderSetting;
        $unrelated->id = 9;
        $unrelated->api_key = 're_unrelated_synthetic_key';
        $unrelated->save();
        $fake = 're_singleton_synthetic_key';

        $this->post('/admin/provider', ['api_key' => $fake, 'password' => 'Local-Password-For-Tests!'])->assertRedirect();

        $this->assertSame($fake, ProviderSetting::findOrFail(1)->api_key);
        $this->assertSame($fake, ProviderSetting::valueFor('api_key'));
        $this->assertSame('re_unrelated_synthetic_key', $unrelated->fresh()->api_key);
        $this->assertFalse(config('brnmail.external_enabled'));
    }

    public function test_invite_expires_and_is_single_use_without_granting_mail(): void
    {
        $token = bin2hex(random_bytes(32));
        $invite = MailInvite::create(['organization_id' => $this->box->domain->product->organization_id, 'email' => 'new@example.test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(1)]);
        $payload = ['token' => $token, 'name' => 'Novo', 'password' => 'Long-Local-Password!', 'password_confirmation' => 'Long-Local-Password!'];
        $this->post('/invite/'.$invite->id, $payload)->assertRedirect('/login');
        $this->post('/invite/'.$invite->id, $payload)->assertStatus(410);
        $new = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertFalse(app(Access::class)->allowed($new, $this->box->id));
        $invite->update(['accepted_at' => null, 'expires_at' => now()->subHour()]);
        $this->post('/invite/'.$invite->id, $payload)->assertStatus(410);
    }

    public function test_queued_reply_rechecks_responsibility_before_provider(): void
    {
        $parent = $this->msg(null, ['assigned_to' => $this->user->id]);
        $draft = $this->draft();
        $draft->update(['reply_source_id' => $parent->id]);
        $outbox = app(OutgoingMail::class)->queue($draft, $this->user, 1);
        $parent->update(['assigned_to' => null]);
        app(OutgoingMail::class)->process($outbox->id);
        $this->assertSame('reply_reassigned', $outbox->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_restore_refuses_nonempty_database(): void
    {
        $service = app(BackupArchive::class);
        $key = random_bytes(32);
        $a = $service->export($key);
        $this->expectExceptionMessage('restore_requires_empty_database');
        $service->restore($a, $key);
    }

    public function test_reconciliation_recovers_missing_webhook_once_and_filters_other_products(): void
    {
        [$event, $data] = $this->event();
        $event->delete();
        $unknown = ['id' => (string) Str::uuid(), 'to' => ['not-mapped@unknown.test'], 'cc' => [], 'bcc' => []];
        config(['brnmail.external_enabled' => true, 'brnmail.transport' => 'resend', 'brnmail.resend_key' => 'fake-key']);
        Http::fake(['api.resend.com/emails/receiving?*' => fn () => Http::response(['data' => [$data, $unknown], 'has_more' => false]), 'api.resend.com/emails/receiving/*' => fn () => Http::response($data)]);
        $service = app(ReconcileMail::class);
        $first = $service->page();
        $second = $service->page();
        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['unmapped_ignored']);
        $this->assertSame(0, $second['created']);
        app(IncomingMail::class)->process(WebhookEvent::first()->id);
        $this->assertSame(1, Message::count());
    }

    public function test_reconciliation_reports_continuation_instead_of_claiming_complete(): void
    {
        [$event, $data] = $this->event();
        config(['brnmail.external_enabled' => true, 'brnmail.transport' => 'resend', 'brnmail.resend_key' => 'fake-key']);
        Http::fake(['api.resend.com/*' => Http::response(['data' => [$data], 'has_more' => true])]);
        $page = app(ReconcileMail::class)->page();
        $this->assertTrue($page['has_more']);
        $this->assertSame($data['id'], $page['next_after']);
    }

    public function test_exact_official_attachment_cdn_allowed_but_suffix_attack_rejected(): void
    {
        $service = app(ReceivedAttachments::class);
        $this->assertSame('inbound-cdn.resend.com', $service->downloadHost('https://inbound-cdn.resend.com/path?signature=fake-test'));
        $this->expectExceptionMessage('attachment_download_host_rejected');
        $service->downloadHost('https://inbound-cdn.resend.com.attacker.test/path');
    }

    public function test_domain_verification_requires_matching_name_and_provider_proof(): void
    {
        $id = (string) Str::uuid();
        config(['brnmail.external_enabled' => true, 'brnmail.transport' => 'resend', 'brnmail.resend_key' => 'fake-key']);
        Http::fake(['api.resend.com/*' => fn () => Http::response(['id' => $id, 'name' => 'a.test', 'status' => 'verified', 'capabilities' => ['sending' => 'enabled', 'receiving' => 'disabled']])]);
        $state = app(DomainStatus::class)->refresh($this->box->domain, $id);
        $this->assertTrue($state['sending_verified']);
        $this->assertFalse($state['receiving_enabled']);
        $this->expectExceptionMessage('provider_domain_mismatch');
        app(DomainStatus::class)->refresh($this->foreign->domain, $id);
    }

    public function test_corrupt_attachment_never_gains_clean_status(): void
    {
        $a = app(Attachments::class)->store($this->draft(), UploadedFile::fake()->createWithContent('texto.txt', 'Original seguro'));
        app(Attachments::class)->scan($a, 'Outro conteúdo');
        $this->assertSame('quarantine', $a->fresh()->status);
        $this->assertSame('attachment_integrity_failed', $a->fresh()->reason);
    }

    public function test_images_and_pdf_pass_the_supported_attachment_contract(): void
    {
        $draft = $this->draft();
        foreach (['jpg', 'png', 'webp'] as $ext) {
            $file = UploadedFile::fake()->image('foto.'.$ext, 32, 32);
            $this->assertSame('clean', $this->scanned($draft, $file)->status);
        }
        $pdf = UploadedFile::fake()->createWithContent('documento.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $this->assertSame('clean', $this->scanned($draft, $pdf)->status);
        $csv = UploadedFile::fake()->createWithContent('dados.csv', "nome,valor\nTeste,10\n");
        $this->assertSame('clean', $this->scanned($draft, $csv)->status);
    }

    public function test_office_containers_require_structure_and_reject_macros(): void
    {
        $draft = $this->draft();
        foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'] as $ext => $root) {
            $path = tempnam(sys_get_temp_dir(), 'brnmail-office-');
            try {
                $zip = new \ZipArchive;
                $zip->open($path, \ZipArchive::OVERWRITE);
                $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types></Types>');
                $zip->addFromString($root, '<?xml version="1.0"?><test>Arquivo sintético</test>');
                $zip->close();
                $this->assertSame('clean', $this->scanned($draft, UploadedFile::fake()->createWithContent('documento.'.$ext, file_get_contents($path)))->status);
                $zip->open($path);
                $zip->addFromString('word/vbaProject.bin', 'macro sintética, não executável');
                $zip->close();
                $this->post('/drafts/'.$draft->id.'/attachments', ['attachment' => UploadedFile::fake()->createWithContent('macro.'.$ext, file_get_contents($path))])->assertStatus(422);
            } finally {
                unlink($path);
            }
        }
    }

    public function test_backup_restores_reply_links_after_parent_rows_exist(): void
    {
        $parent = $this->msg();
        $draft = $this->draft();
        $draft->update(['reply_source_id' => $parent->id]);
        $service = app(BackupArchive::class);
        $key = random_bytes(32);
        $archive = $service->export($key);
        DB::table('messages')->update(['reply_source_id' => null]);
        foreach (array_reverse(BackupArchive::TABLES) as $table) {
            DB::table($table)->delete();
        }
        $service->restore($archive, $key);
        $this->assertSame($parent->id, Message::findOrFail($draft->id)->reply_source_id);
    }

    public function test_reply_index_is_private_and_never_links_same_id_across_companies(): void
    {
        $rfc = '<same-id@example.test>';
        $own = $this->msg(null, ['rfc_message_id' => $rfc, 'created_at' => now()->subYears(2)]);
        $foreign = $this->msg($this->foreign, ['rfc_message_id' => $rfc]);
        [$event, $data] = $this->event(['headers' => ['in-reply-to' => $rfc]]);
        app(IncomingMail::class)->ingest($event, $data);
        $received = Message::where('provider_id', $event->email_id)->firstOrFail();
        $this->assertSame($own->thread_id, $received->thread_id);
        $this->assertNotSame($foreign->thread_id, $received->thread_id);
        $this->assertNotSame($own->rfc_digest, $foreign->rfc_digest);
        $this->assertStringNotContainsString($rfc, DB::table('messages')->where('id', $own->id)->value('rfc_digest'));
    }

    public function test_sent_webhook_stores_private_rfc_id_and_links_the_external_return(): void
    {
        $providerId = (string) Str::uuid();
        $rfc = '<sent-by-provider@example.test>';
        $sent = $this->draft();
        $sent->update(['provider_id' => $providerId, 'status' => 'accepted']);
        $foreign = $this->msg($this->foreign, ['rfc_message_id' => $rfc]);
        [$body, $headers] = $this->signed(['type' => 'email.sent', 'data' => ['email_id' => $providerId, 'message_id' => $rfc, 'subject' => 'Do not persist provider content']]);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
        $event = WebhookEvent::sole();
        $this->assertSame(['type' => 'email.sent', 'data' => ['email_id' => $providerId, 'message_id' => $rfc]], $event->payload);
        $this->assertStringNotContainsString($rfc, DB::table('webhook_events')->where('id', $event->id)->value('payload'));
        app(IncomingMail::class)->process($event->id);
        $this->assertSame($rfc, $sent->fresh()->rfc_message_id);
        $this->assertStringNotContainsString($rfc, DB::table('messages')->where('id', $sent->id)->value('rfc_message_id'));
        [$received, $data] = $this->event(['headers' => ['In-Reply-To' => $rfc]]);
        app(IncomingMail::class)->ingest($received, $data);
        $reply = Message::where('provider_id', $received->email_id)->sole();
        $this->assertSame($sent->thread_id, $reply->thread_id);
        $this->assertNotSame($foreign->thread_id, $reply->thread_id);
        app(IncomingMail::class)->ingest($received, $data);
        $this->assertSame(1, Message::where('provider_id', $received->email_id)->count());
    }

    public function test_late_sent_event_adds_rfc_id_without_downgrading_delivery(): void
    {
        $sent = $this->draft();
        $sent->update(['provider_id' => (string) Str::uuid(), 'status' => 'delivered']);
        [$body, $headers] = $this->signed(['type' => 'email.sent', 'data' => ['email_id' => $sent->provider_id, 'message_id' => '<late@example.test>']]);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
        app(IncomingMail::class)->process(WebhookEvent::sole()->id);
        $this->assertSame('delivered', $sent->fresh()->status);
        $this->assertSame('<late@example.test>', $sent->fresh()->rfc_message_id);
    }

    public function test_conflicting_sent_rfc_id_is_quarantined_without_overwrite(): void
    {
        $sent = $this->draft();
        $sent->update(['provider_id' => (string) Str::uuid(), 'status' => 'accepted', 'rfc_message_id' => '<original@example.test>']);
        [$body, $headers] = $this->signed(['type' => 'email.delivered', 'data' => ['email_id' => $sent->provider_id, 'message_id' => '<conflict@example.test>']]);
        $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
        app(IncomingMail::class)->process(WebhookEvent::sole()->id);
        $this->assertSame('provider_message_id_mismatch', WebhookEvent::sole()->last_error);
        $this->assertSame('quarantine', WebhookEvent::sole()->status);
        $this->assertSame('<original@example.test>', $sent->fresh()->rfc_message_id);
        $this->assertSame('accepted', $sent->fresh()->status);
    }

    public function test_invalid_sent_rfc_metadata_does_not_poison_delivery(): void
    {
        foreach ([['not-a-string'], "<bad@example.test>\r\nX-Other: injected", '<'.str_repeat('a', 1000).'>', 'unbracketed@example.test'] as $bad) {
            $sent = $this->draft();
            $sent->update(['provider_id' => (string) Str::uuid(), 'status' => 'accepted']);
            [$body, $headers] = $this->signed(['type' => 'email.delivered', 'data' => ['email_id' => $sent->provider_id, 'message_id' => $bad]]);
            $this->call('POST', '/webhooks/resend', [], [], [], $headers, $body)->assertStatus(202);
            $event = WebhookEvent::where('email_id', $sent->provider_id)->sole();
            $this->assertArrayNotHasKey('message_id', $event->payload['data']);
            app(IncomingMail::class)->process($event->id);
            $this->assertSame('delivered', $sent->fresh()->status);
            $this->assertNull($sent->fresh()->rfc_message_id);
        }
    }

    public function test_references_link_return_before_sent_event_using_only_same_mailbox(): void
    {
        $rfc = '<original-client@example.test>';
        $parent = $this->msg(null, ['rfc_message_id' => $rfc]);
        $foreign = $this->msg($this->foreign, ['rfc_message_id' => $rfc]);
        [$event, $data] = $this->event(['headers' => ['In-Reply-To' => '<not-yet-known@example.test>', 'References' => $rfc.' <not-yet-known@example.test>']]);
        app(IncomingMail::class)->ingest($event, $data);
        $reply = Message::where('provider_id', $event->email_id)->sole();
        $this->assertSame($parent->thread_id, $reply->thread_id);
        $this->assertNotSame($foreign->thread_id, $reply->thread_id);
    }

    public function test_ambiguous_foreign_or_malformed_references_do_not_merge_threads(): void
    {
        $one = $this->msg(null, ['rfc_message_id' => '<one@example.test>']);
        $two = $this->msg(null, ['rfc_message_id' => '<two@example.test>']);
        $foreign = $this->msg($this->foreign, ['rfc_message_id' => '<foreign@example.test>']);
        foreach (['<one@example.test> <two@example.test>', '<foreign@example.test>', ['<one@example.test>'], '<one@example.test> broken', str_repeat('<one@example.test> ', 51)] as $references) {
            [$event, $data] = $this->event(['headers' => ['in-reply-to' => '<missing@example.test>', 'references' => $references]]);
            app(IncomingMail::class)->ingest($event, $data);
            $reply = Message::where('provider_id', $event->email_id)->sole();
            $this->assertNotContains($reply->thread_id, [$one->thread_id, $two->thread_id, $foreign->thread_id]);
            $this->assertSame('received', $reply->status);
        }
    }
}
