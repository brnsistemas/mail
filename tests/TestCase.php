<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    private static bool $schemaPrepared = false;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'brnmail_test' || config('database.connections.mysql.host') !== '127.0.0.1' || config('database.connections.mysql.url') || (string) config('database.connections.mysql.port') !== '33461') {
            throw new \RuntimeException('QA recusado: exige MySQL brnmail_test isolado na porta 33461.');
        }

        if (! self::$schemaPrepared) {
            // The connection guard above must pass before any schema change.
            // Add missing migrations once; each feature test rolls back its own rows.
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new \RuntimeException('QA recusado: migrations do banco isolado não concluídas.');
            }
            self::$schemaPrepared = true;
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['brnmail.external_enabled' => false, 'brnmail.transport' => 'local', 'brnmail.scanner' => 'test']);
    }
}
