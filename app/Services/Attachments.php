<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final class Attachments
{
    private const MIME = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip']];

    public function metadataAllowed(string $name, string $mime): bool
    {
        return $name !== '' && strlen($name) <= 180 && ! preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name) && substr_count($name, '.') === 1 && isset(self::MIME[strtolower(pathinfo($name, PATHINFO_EXTENSION))]) && in_array(strtolower($mime), self::MIME[strtolower(pathinfo($name, PATHINFO_EXTENSION))], true);
    }

    public function store(Message $message, UploadedFile $file): Attachment
    {
        $this->validateFile($file);
        $name = $file->getClientOriginalName();
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = $file->getSize();

        return DB::transaction(function () use ($message, $name, $mime, $size, $path) {
            $m = Message::lockForUpdate()->findOrFail($message->id);
            abort_unless($m->status === 'draft', 409);
            abort_if($m->attachments()->count() >= config('brnmail.attachments_count') || $m->attachments()->sum('size') + $size > config('brnmail.attachments_total'), 422, 'Limite de anexos por mensagem.');
            $bytes = file_get_contents($path);
            $id = (string) Str::uuid();
            $stored = 'mail/'.$id.'.bin';
            Storage::disk('local')->put($stored, Crypt::encryptString($bytes));

            return Attachment::create(['id' => $id, 'message_id' => $m->id, 'filename' => $name, 'mime' => $mime, 'size' => $size, 'sha256' => hash('sha256', $bytes), 'path' => $stored, 'status' => 'quarantine']);
        });
    }

    public function validateFile(UploadedFile $file): void
    {
        $name = $file->getClientOriginalName();
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = $file->getSize();
        abort_unless($size > 0 && $size <= config('brnmail.attachment_max') && $this->metadataAllowed($name, $mime), 422, 'Formato, nome ou tamanho do anexo não permitido.');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $reason = null;
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $info = @getimagesize($path);
            if (! $info || $info[0] * $info[1] > 40000000 || ($info['mime'] ?? '') !== $mime) {
                $reason = 'invalid_image';
            }
        }
        if ($ext === 'pdf' && ! str_starts_with(file_get_contents($path, false, null, 0, 5), '%PDF-')) {
            $reason = 'invalid_pdf';
        }
        if (in_array($ext, ['docx', 'xlsx', 'pptx'])) {
            $reason = $this->office($path, $ext);
        }
        if (in_array($ext, ['txt', 'csv']) && ! mb_check_encoding(file_get_contents($path), 'UTF-8')) {
            $reason = 'invalid_text';
        }
        abort_if($reason, 422, 'Estrutura do arquivo não permitida.');
    }

    public function scan(Attachment $a, ?string $bytes = null): bool
    {
        $token = (string) Str::uuid();
        $claimed = Attachment::whereKey($a->id)->dueForScan()->update([
            'scan_attempts' => DB::raw('scan_attempts + 1'),
            'scan_attempted_at' => now(),
            'scan_lease_until' => now()->addSeconds(120),
            'scan_lease_token' => $token,
        ]);
        if (! $claimed) {
            return false;
        }
        $a->refresh();
        try {
            $bytes ??= Crypt::decryptString(Storage::disk('local')->get($a->path));
        } catch (\Throwable) {
            return $this->scanResult($a, $token, 'quarantine', 'attachment_storage_unreadable');
        }
        if (! hash_equals($a->sha256 ?? '', hash('sha256', $bytes))) {
            return $this->scanResult($a, $token, 'quarantine', 'attachment_integrity_failed');
        }
        try {
            if (config('brnmail.scanner') === 'test' && app()->environment('testing')) {
                $clean = ! str_contains($bytes, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE');
            } else {
                if (config('brnmail.scanner') !== 'clamd') {
                    throw new RuntimeException('scanner_unavailable');
                }
                $clean = app(ClamAvScanner::class)->scan($bytes, (string) config('brnmail.clamd_host'), (int) config('brnmail.clamd_port'));
            }
        } catch (\Throwable) {
            return $this->scanResult($a, $token, 'quarantine', 'scanner_unavailable');
        }

        return $this->scanResult($a, $token, $clean ? 'clean' : 'blocked', $clean ? null : 'malware_detected');
    }

    private function scanResult(Attachment $a, string $token, string $status, ?string $reason): bool
    {
        return DB::transaction(function () use ($a, $token, $status, $reason) {
            $locked = Attachment::whereKey($a->id)->where('status', 'quarantine')->where('scan_lease_token', $token)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }
            $locked->update([
                'status' => $status,
                'reason' => $reason,
                'scan_available_at' => $status === 'quarantine' ? now()->addSeconds($reason === 'scanner_unavailable' ? 60 : 3600) : null,
                'scan_lease_until' => null,
                'scan_lease_token' => null,
            ]);
            app(Access::class)->audit(null, 'attachment.scan.'.($reason ?? 'clean'), $locked->message?->mailbox_id, $locked->id);

            return true;
        });
    }

    private function office(string $path, string $ext): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return 'invalid_office';
        }
        try {
            if ($zip->numFiles > 1000 || $zip->locateName('[Content_Types].xml') === false) {
                return 'invalid_office';
            }
            $total = 0;
            $root = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$ext];
            if ($zip->locateName($root) === false) {
                return 'invalid_office';
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $s = $zip->statIndex($i);
                $total += $s['size'];
                if ($total > 40 * 1024 * 1024 || str_contains($s['name'], '..') || str_starts_with($s['name'], '/') || preg_match('/vbaProject|\.exe$|\.bin$|embeddings\//i', $s['name']) || ($s['encryption_method'] ?? 0) !== 0) {
                    return 'unsafe_office';
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }
}
