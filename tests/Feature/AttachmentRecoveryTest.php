<?php

namespace Tests\Feature;

use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Attachments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AttachmentRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = Organization::create(['name' => 'Recovery QA']);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'QA']);
        $domain = MailDomain::create(['product_id' => $product->id, 'domain' => 'recovery.test']);
        $box = Mailbox::create(['mail_domain_id' => $domain->id, 'name' => 'QA', 'address' => 'qa@recovery.test']);
        $this->message = Message::create(['mailbox_id' => $box->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'outbound', 'folder' => 'drafts', 'status' => 'draft', 'subject' => 'Synthetic', 'sender' => $box->address, 'recipients' => ['to' => ['recipient@example.test'], 'cc' => [], 'bcc' => []], 'body_text' => 'Synthetic']);
    }

    private function attachment(string $bytes = 'Private synthetic content'): Attachment
    {
        return app(Attachments::class)->store($this->message, UploadedFile::fake()->createWithContent('confidential.txt', $bytes));
    }

    public function test_scheduler_recovers_a_one_attempt_job_after_scanner_outage(): void
    {
        $a = $this->attachment();
        config(['brnmail.scanner' => 'disabled']);
        (new ScanAttachment($a->id))->handle(app(Attachments::class));
        $this->assertSame('quarantine', $a->fresh()->status);
        $this->assertSame('scanner_unavailable', $a->fresh()->reason);
        $this->assertSame(1, $a->fresh()->scan_attempts);
        $this->assertFalse(app(Attachments::class)->scan($a));

        config(['brnmail.scanner' => 'test']);
        $this->travel(61)->seconds();
        $this->artisan('brnmail:scan')->expectsOutput('attempted=1 clean=1 blocked=0 quarantine=0 skipped=0 errors=0')->assertSuccessful();
        $this->assertSame('clean', $a->fresh()->status);
        $this->assertSame(2, $a->fresh()->scan_attempts);
        $this->assertNull($a->fresh()->scan_lease_token);
        $this->assertDatabaseHas('mail_audits', ['resource_id' => $a->id, 'action' => 'attachment.scan.scanner_unavailable']);
        $this->assertDatabaseHas('mail_audits', ['resource_id' => $a->id, 'action' => 'attachment.scan.clean']);
    }

    public function test_unreadable_ciphertext_does_not_interrupt_the_batch(): void
    {
        $broken = $this->attachment();
        $clean = $this->attachment();
        Storage::disk('local')->put($broken->path, 'corrupted ciphertext');
        $this->artisan('brnmail:scan')->expectsOutput('attempted=2 clean=1 blocked=0 quarantine=1 skipped=0 errors=0')->assertFailed();
        $this->assertSame('attachment_storage_unreadable', $broken->fresh()->reason);
        $this->assertSame('quarantine', $broken->fresh()->status);
        $this->assertSame('clean', $clean->fresh()->status);
        $this->assertDatabaseHas('mail_audits', ['resource_id' => $broken->id, 'action' => 'attachment.scan.attachment_storage_unreadable']);
    }

    public function test_old_permanent_failure_does_not_monopolize_a_one_item_batch(): void
    {
        $broken = $this->attachment();
        $broken->update(['sha256' => str_repeat('0', 64), 'created_at' => now()->subDay()]);
        $this->artisan('brnmail:scan', ['--limit' => 1])->assertFailed();
        $clean = $this->attachment();
        $this->travel(61)->minutes();
        $this->artisan('brnmail:scan', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('clean', $clean->fresh()->status);
        $this->assertSame(1, $broken->fresh()->scan_attempts);
        $this->assertSame('attachment_integrity_failed', $broken->fresh()->reason);
    }

    public function test_active_claim_is_skipped_and_expired_claim_is_recovered(): void
    {
        $a = $this->attachment();
        $a->update(['scan_lease_token' => (string) Str::uuid(), 'scan_lease_until' => now()->addSeconds(120)]);
        $this->assertFalse(app(Attachments::class)->scan($a));
        $this->assertSame(0, $a->fresh()->scan_attempts);
        $this->travel(121)->seconds();
        $this->assertTrue(app(Attachments::class)->scan($a));
        $this->assertSame('clean', $a->fresh()->status);
    }

    public function test_completed_verdict_is_not_scanned_or_audited_twice(): void
    {
        $a = $this->attachment('EICAR-STANDARD-ANTIVIRUS-TEST-FILE');
        $service = app(Attachments::class);
        $this->assertTrue($service->scan($a));
        $this->assertFalse($service->scan($a));
        $this->assertSame('blocked', $a->fresh()->status);
        $this->assertDatabaseCount('mail_audits', 1);
        $audit = json_encode(DB::table('mail_audits')->get());
        foreach ([$a->filename, $a->path, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE'] as $private) {
            $this->assertStringNotContainsString($private, $audit);
        }
    }

    public function test_failed_audit_cannot_commit_a_clean_verdict(): void
    {
        $a = $this->attachment();
        DB::connection()->beforeExecuting(function ($query) {
            if (str_starts_with($query, 'insert into `mail_audits`')) {
                throw new RuntimeException('Synthetic audit outage');
            }
        });
        $this->artisan('brnmail:scan')->expectsOutput('attempted=0 clean=0 blocked=0 quarantine=0 skipped=0 errors=1')->assertFailed();
        $this->assertSame('quarantine', $a->fresh()->status);
        $this->assertDatabaseCount('mail_audits', 0);
    }
}
