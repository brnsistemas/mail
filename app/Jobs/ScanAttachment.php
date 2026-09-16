<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Services\Attachments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public string $attachmentId)
    {
        $this->onQueue('attachments');
    }

    public function handle(Attachments $service): void
    {
        $a = Attachment::find($this->attachmentId);
        if ($a && $a->status === 'quarantine' && $a->path) {
            $service->scan($a);
        }
    }
}
