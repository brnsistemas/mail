<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Services\Attachments;
use App\Services\ReceivedAttachments;
use Illuminate\Console\Command;

class ScanMail extends Command
{
    protected $signature = 'brnmail:scan {--limit=10}';

    protected $description = 'Verifica novamente anexos privados em quarentena.';

    public function handle(Attachments $service): int
    {
        $errors = 0;
        if (config('brnmail.external_enabled')) {
            foreach (Attachment::where('status', 'unavailable')->oldest('updated_at')->orderBy('id')->limit(5)->get() as $a) {
                try {
                    app(ReceivedAttachments::class)->process($a);
                    $a->touch();
                } catch (\Throwable) {
                    $errors++;
                }
            }
        }
        $items = Attachment::dueForScan()->orderByRaw('COALESCE(scan_available_at, created_at)')->orderBy('created_at')->orderBy('id')
            ->limit(max(1, min(25, (int) $this->option('limit'))))->get();
        $counts = ['attempted' => 0, 'clean' => 0, 'blocked' => 0, 'quarantine' => 0, 'skipped' => 0];
        foreach ($items as $a) {
            try {
                if (! $service->scan($a)) {
                    $counts['skipped']++;

                    continue;
                }
                $counts['attempted']++;
                $status = $a->fresh()?->status;
                if (in_array($status, ['clean', 'blocked', 'quarantine'], true)) {
                    $counts[$status]++;
                } else {
                    $errors++;
                }
            } catch (\Throwable) {
                // A broken attachment or failed audit must not hide the remaining batch.
                // No filename, content, path or exception text is printed.
                $errors++;
            }
        }
        $counts['errors'] = $errors;
        $this->line(collect($counts)->map(fn ($value, $name) => $name.'='.$value)->implode(' '));

        return $errors > 0 || $counts['quarantine'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
