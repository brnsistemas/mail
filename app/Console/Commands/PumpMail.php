<?php

namespace App\Console\Commands;

use App\Jobs\ProcessIncoming;
use App\Jobs\ProcessOutgoing;
use App\Models\MailOutbox;
use App\Models\WebhookEvent;
use App\Services\IncomingMail;
use App\Services\OutgoingMail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PumpMail extends Command
{
    protected $signature = 'brnmail:pump {--inline : Executar no processo atual, sem Redis} {--limit=25}';

    protected $description = 'Recupera trabalho durável pendente; não depende da disponibilidade do Redis para preservar registros.';

    public function handle(IncomingMail $in, OutgoingMail $out): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $sent = 0;
        $failed = 0;
        foreach ([[WebhookEvent::class, ['pending', 'retry', 'processing']], [MailOutbox::class, ['pending', 'retry', 'sending']]] as [$class,$states]) {
            $items = $class::whereIn('status', $states)->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->oldest()->limit($limit)->get();
            foreach ($items as $item) {
                try {
                    if (! $this->option('inline') && $item->enqueued_at && Carbon::parse($item->enqueued_at)->gt(now()->subMinutes(2))) {
                        continue;
                    }
                    if ($this->option('inline')) {
                        $class === WebhookEvent::class ? $in->process($item->id) : $out->process($item->id);
                    } else {
                        $class === WebhookEvent::class ? ProcessIncoming::dispatch($item->id) : ProcessOutgoing::dispatch($item->id);
                    }
                    if (! $this->option('inline')) {
                        $item->update(['enqueued_at' => now()]);
                    }
                    $sent++;
                } catch (\Throwable) {
                    $failed++;
                }
            }
        }
        $this->line('work_items='.$sent.' dispatch_errors='.$failed);

        return $failed ? 1 : 0;
    }
}
