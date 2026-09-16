<?php

namespace App\Jobs;

use App\Services\IncomingMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessIncoming implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $eventId)
    {
        $this->onQueue('inbound');
    }

    public function handle(IncomingMail $service): void
    {
        $service->process($this->eventId);
    }
}
