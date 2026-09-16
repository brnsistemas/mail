<?php

namespace Tests\Unit;

use App\Services\ClamAvScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ClamAvScannerTest extends TestCase
{
    public static function replies(): array
    {
        return [
            'clean' => ["stream: OK\0", true],
            'detection' => ["stream: Eicar-Test-Signature FOUND\0", false],
            'limit heuristic' => ["stream: Heuristics.Limits.Exceeded.MaxFileSize FOUND\0", false],
            'suffix is not a verdict' => ["stream: NOTOK\0", null],
            'error ending in OK' => ["stream: read ERROR OK\0", null],
            'unknown prefix' => ["other: OK\0", null],
            'missing terminator' => ['stream: OK', null],
            'wrong terminator' => ["stream: OK\n", null],
            'duplicate replies' => ["stream: OK\0stream: Eicar FOUND\0", null],
            'size error' => ["INSTREAM size limit exceeded. ERROR\0", null],
            'truncated' => ['stream: ', null],
            'empty' => ['', null],
            'oversized' => [str_repeat('a', 1025)."\0", null],
        ];
    }

    #[DataProvider('replies')]
    public function test_only_complete_recognized_replies_produce_a_verdict(string $reply, ?bool $expected): void
    {
        if ($expected === null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('scanner_unavailable');
        }
        $bytes = str_repeat('Synthetic attachment ', 1200);
        $actual = $this->withPeer($bytes, [$reply], [], fn ($port) => (new ClamAvScanner)->scan($bytes, '127.0.0.1', $port));
        $this->assertSame($expected, $actual);
    }

    public function test_fragmented_reply_is_assembled(): void
    {
        $this->assertTrue($this->withPeer('test', ['stream:', ' OK', "\0"], ['delay_us' => 10_000], fn ($port) => (new ClamAvScanner)->scan('test', '127.0.0.1', $port)));
    }

    public function test_drip_response_does_not_reset_the_total_deadline(): void
    {
        $this->withPeer('test', ['stream:', ' OK', "\0"], ['delay_us' => 120_000], function ($port) {
            $started = hrtime(true);
            try {
                (new ClamAvScanner)->scan('test', '127.0.0.1', $port, 0.2);
                $this->fail('A slow response must not become clean.');
            } catch (RuntimeException $error) {
                $elapsed = (hrtime(true) - $started) / 1_000_000_000;
                $this->assertSame('scanner_unavailable', $error->getMessage());
                $this->assertGreaterThanOrEqual(0.15, $elapsed);
                $this->assertLessThan(0.6, $elapsed);
            }
        });
    }

    public function test_peer_that_does_not_read_cannot_block_the_writer(): void
    {
        $bytes = str_repeat('a', 10 * 1024 * 1024);
        $this->withPeer($bytes, [], ['no_read' => true], function ($port) use ($bytes) {
            $started = hrtime(true);
            try {
                (new ClamAvScanner)->scan($bytes, '127.0.0.1', $port, 0.2);
                $this->fail('A blocked write must fail.');
            } catch (RuntimeException $error) {
                $this->assertSame('scanner_unavailable', $error->getMessage());
                $this->assertLessThan(0.6, (hrtime(true) - $started) / 1_000_000_000);
            }
        });
    }

    public function test_hostnames_cannot_trigger_an_unbounded_dns_lookup(): void
    {
        $this->expectException(RuntimeException::class);
        (new ClamAvScanner)->scan('test', 'scanner.example.invalid', 3310);
    }

    private function withPeer(string $bytes, array $parts, array $options, callable $run): mixed
    {
        $scenario = array_merge(['sha256' => hash('sha256', $bytes), 'parts' => array_map('base64_encode', $parts)], $options);
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/Fixtures/clamd-server.php', json_encode($scenario, JSON_THROW_ON_ERROR)], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 5);
            $address = trim(fgets($pipes[1]) ?: '');
            $this->assertMatchesRegularExpression('/\A127\.0\.0\.1:[0-9]+\z/', $address);

            return $run((int) substr($address, strrpos($address, ':') + 1));
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}
