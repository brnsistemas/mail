<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class BackupArchive
{
    public const TABLES = ['users', 'organizations', 'memberships', 'products', 'mail_domains', 'mailboxes', 'mailbox_grants', 'mail_aliases', 'messages', 'message_search', 'attachments', 'mail_outbox', 'webhook_events', 'mail_suppressions', 'mail_audits', 'mail_invites', 'provider_settings'];

    public function export(string $key): string
    {
        $this->key($key);
        $bytes = 0;
        $snapshot = DB::transaction(function () use (&$bytes) {
            $tables = [];
            foreach (self::TABLES as $table) {
                if (DB::table($table)->count() > 100000) {
                    throw new RuntimeException('backup_limit_exceeded');
                }
                $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
                $bytes += strlen(json_encode($tables[$table]));
                if ($bytes > 48 * 1024 * 1024) {
                    throw new RuntimeException('backup_limit_exceeded');
                }
            }
            $files = [];
            foreach ($tables['attachments'] as $a) {
                if ($path = $a['path']) {
                    if (! preg_match('#^mail/[a-f0-9-]{36}\.bin$#D', $path) || ! Storage::disk('local')->exists($path)) {
                        throw new RuntimeException('backup_attachment_missing');
                    }
                    $raw = Storage::disk('local')->get($path);
                    $bytes += strlen($raw);
                    if ($bytes > 48 * 1024 * 1024) {
                        throw new RuntimeException('backup_limit_exceeded');
                    }
                    $files[$path] = ['sha256' => hash('sha256', $raw), 'data' => base64_encode($raw)];
                }
            }

            return ['version' => 1, 'created_at' => now()->toIso8601String(), 'app_key_fingerprint' => hash('sha256', config('app.key')), 'migration_names' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all(), 'tables' => $tables, 'files' => $files];
        });

        return (new Encrypter($key, 'AES-256-CBC'))->encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function inspect(string $archive, string $key): array
    {
        $this->key($key);
        if (strlen($archive) > 96 * 1024 * 1024) {
            throw new RuntimeException('backup_limit_exceeded');
        }
        try {
            $d = json_decode((new Encrypter($key, 'AES-256-CBC'))->decryptString($archive), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('backup_integrity_failed');
        }
        if (($d['version'] ?? 0) !== 1 || ! hash_equals(hash('sha256', config('app.key')), $d['app_key_fingerprint'] ?? '') || array_keys($d['tables'] ?? []) !== self::TABLES) {
            throw new RuntimeException('backup_contract_mismatch');
        }
        if (($d['migration_names'] ?? []) !== DB::table('migrations')->orderBy('migration')->pluck('migration')->all()) {
            throw new RuntimeException('backup_schema_mismatch');
        }
        foreach ($d['files'] as $path => $f) {
            if (! preg_match('#^mail/[a-f0-9-]{36}\.bin$#D', $path) || ! hash_equals($f['sha256'], hash('sha256', base64_decode($f['data'], true) ?: ''))) {
                throw new RuntimeException('backup_file_integrity_failed');
            }
        }
        foreach ($d['tables']['attachments'] as $a) {
            if ($a['path'] && ! isset($d['files'][$a['path']])) {
                throw new RuntimeException('backup_attachment_missing');
            }
        }

        return $d;
    }

    public function restore(string $archive, string $key): array
    {
        if (! app()->environment(['local', 'testing']) || ! in_array(DB::connection()->getDatabaseName(), ['brnmail_restore', 'brnmail_test'], true)) {
            throw new RuntimeException('restore_requires_isolated_database');
        }
        $d = $this->inspect($archive, $key);
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('restore_requires_empty_database');
            }
        }
        $created = [];
        try {
            foreach ($d['files'] as $path => $f) {
                if (Storage::disk('local')->exists($path)) {
                    throw new RuntimeException('restore_file_exists');
                } Storage::disk('local')->put($path, base64_decode($f['data'], true));
                $created[] = $path;
            }
            DB::transaction(function () use ($d) {
                $replyLinks = [];
                foreach (self::TABLES as $table) {
                    $rows = $d['tables'][$table];
                    if ($table === 'messages') {
                        foreach ($rows as &$row) {
                            if ($row['reply_source_id']) {
                                $replyLinks[$row['id']] = $row['reply_source_id'];
                                $row['reply_source_id'] = null;
                            }
                        }
                        unset($row);
                    }
                    foreach (array_chunk($rows, 50) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
                foreach ($replyLinks as $id => $parent) {
                    DB::table('messages')->where('id', $id)->update(['reply_source_id' => $parent]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($created as $path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return array_map('count', $d['tables']);
    }

    private function key(string $key): void
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('backup_key_must_be_32_bytes');
        }
    }
}
