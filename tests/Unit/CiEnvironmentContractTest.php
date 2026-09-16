<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class CiEnvironmentContractTest extends TestCase
{
    private function job(): array
    {
        return Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/qa.yml')['jobs']['local-contract'];
    }

    private function step(array $job, string $command): array
    {
        $steps = array_filter($job['steps'], fn ($step) => ($step['run'] ?? '') === $command);
        $this->assertCount(1, $steps, 'O comando precisa ter uma etapa inequívoca no workflow.');

        return reset($steps);
    }

    public function test_database_test_steps_override_the_inherited_demo_environment_before_php_starts(): void
    {
        $job = $this->job();
        foreach (['php artisan test --compact', 'php tests/Concurrency/run.php'] as $command) {
            $step = $this->step($job, $command);
            // An XML env force/putenv cannot repair an inherited $_SERVER value.
            // Each destructive QA step must carry its own process environment.
            $this->assertSame('testing', $step['env']['APP_ENV'] ?? null);
            $this->assertSame('mysql', $step['env']['DB_CONNECTION'] ?? null);
            $this->assertSame('brnmail_test', $step['env']['DB_DATABASE'] ?? null);
            $this->assertSame('', $step['env']['DB_URL'] ?? null);
            $effective = array_replace($job['env'], $step['env']);
            $this->assertSame('127.0.0.1', $effective['DB_HOST']);
            $this->assertSame('33461', (string) $effective['DB_PORT']);
            $this->assertSame('false', $effective['BRNMAIL_EXTERNAL_ENABLED']);
        }
    }

    public function test_browser_identity_server_and_pump_share_the_demo_database_after_sequential_backend_tests(): void
    {
        $job = $this->job();
        $commands = array_column($job['steps'], 'run');
        $unit = array_search('php artisan test --compact', $commands, true);
        $concurrency = array_search('php tests/Concurrency/run.php', $commands, true);
        $seedCommand = 'php artisan migrate --force && php artisan db:seed --class=LocalDemoSeeder && php artisan db:seed --class=LocalWorkspaceDemoSeeder';
        $serverCommand = 'php artisan serve --host=127.0.0.1 --port=8876 --no-reload > storage/logs/qa-server.log 2>&1 &';
        $seed = array_search($seedCommand, $commands, true);
        $server = array_search($serverCommand, $commands, true);
        $browser = array_search('npm run qa:browser', $commands, true);
        foreach ([$unit, $concurrency, $seed, $server, $browser] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertTrue($unit < $concurrency && $concurrency < $seed && $seed < $server && $server < $browser);
        foreach ([$seedCommand, $serverCommand, 'npm run qa:browser'] as $command) {
            $step = $this->step($job, $command);
            $effective = array_replace($job['env'], $step['env'] ?? []);
            $this->assertSame('local', $effective['APP_ENV']);
            $this->assertSame('brnmail', $effective['DB_DATABASE']);
            $this->assertSame('false', $effective['BRNMAIL_EXTERNAL_ENABLED']);
        }
    }
}
