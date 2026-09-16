<?php

namespace App\Jobs;

use App\Services\OutgoingMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessOutgoing implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $outboxId)
    {
        $this->onQueue('outbound');
    }

    public function handle(OutgoingMail $service): void
    {
        $service->process($this->outboxId);
    }
}
