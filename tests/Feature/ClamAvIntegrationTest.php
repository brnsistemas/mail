<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Attachments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClamAvIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_real_clamav_accepts_document_and_rejects_standard_test_signature(): void
    {
        if (getenv('BRNMAIL_TEST_SCANNER') !== '1') {
            $this->markTestSkipped('Execute BRNMAIL_TEST_SCANNER=1 com o container scanner local.');
        }
        config(['brnmail.scanner' => 'clamd']);
        $o = Organization::create(['name' => 'Scanner QA']);
        $p = Product::create(['organization_id' => $o->id, 'name' => 'Test']);
        $d = MailDomain::create(['product_id' => $p->id, 'domain' => 'scanner.test']);
        $b = Mailbox::create(['mail_domain_id' => $d->id, 'name' => 'QA', 'address' => 'qa@scanner.test']);
        $m = Message::create(['mailbox_id' => $b->id, 'thread_id' => (string) Str::uuid(), 'direction' => 'outbound', 'folder' => 'drafts', 'status' => 'draft', 'subject' => 'QA', 'sender' => $b->address, 'recipients' => ['to' => ['recipient@example.test'], 'cc' => [], 'bcc' => []], 'body_text' => 'Teste sintético']);
        $service = app(Attachments::class);
        $clean = $service->store($m, UploadedFile::fake()->createWithContent('texto.txt', 'Documento sintético sem vírus.'));
        $service->scan($clean);
        $this->assertSame('clean', $clean->fresh()->status);
        $eicar = 'X5O!P%@AP[4'.'\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $infected = $service->store($m, UploadedFile::fake()->createWithContent('eicar.txt', $eicar));
        $service->scan($infected);
        $this->assertSame('blocked', $infected->fresh()->status);
        $this->assertSame('malware_detected', $infected->fresh()->reason);
    }
}
