<?php

namespace App\Services;

use RuntimeException;

final class ClamAvScanner
{
    public function scan(string $bytes, string $host, int $port, float $timeout = 20): bool
    {
        // Numeric addresses avoid an unbounded DNS lookup before the socket timeout.
        $host = $host === 'localhost' ? '127.0.0.1' : $host;
        if (! filter_var($host, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535 || ! is_finite($timeout) || $timeout <= 0 || $timeout > 20) {
            throw new RuntimeException('scanner_unavailable');
        }
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);
        $address = str_contains($host, ':') ? '['.$host.']' : $host;
        $socket = @stream_socket_client('tcp://'.$address.':'.$port, $errno, $error, min(3, $timeout));
        if (! $socket) {
            throw new RuntimeException('scanner_unavailable');
        }
        try {
            if (! stream_set_blocking($socket, false)) {
                throw new RuntimeException('scanner_unavailable');
            }
            $write = function (string $data) use ($socket, $deadline): void {
                for ($offset = 0; $offset < strlen($data); $offset += $written) {
                    $this->wait($socket, $deadline, true);
                    $written = @fwrite($socket, substr($data, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('scanner_unavailable');
                    }
                }
            };
            $write("zINSTREAM\0");
            for ($offset = 0; $offset < strlen($bytes); $offset += 8192) {
                $chunk = substr($bytes, $offset, 8192);
                $write(pack('N', strlen($chunk)).$chunk);
            }
            $write(pack('N', 0));

            // A non-session clamd reply must be complete, bounded and NUL-terminated.
            $reply = '';
            do {
                $this->wait($socket, $deadline, false);
                $part = @fread($socket, 1025 - strlen($reply));
                if ($part === false || ($part === '' && ! feof($socket))) {
                    throw new RuntimeException('scanner_unavailable');
                }
                $reply .= $part;
                if (strlen($reply) > 1024) {
                    throw new RuntimeException('scanner_unavailable');
                }
            } while (! feof($socket));

            if ($reply === "stream: OK\0") {
                return true;
            }
            if (preg_match('/\Astream: [^\x00-\x1f\x7f]+ FOUND\x00\z/', $reply) === 1) {
                return false;
            }
            throw new RuntimeException('scanner_unavailable');
        } finally {
            fclose($socket);
        }
    }

    private function wait($socket, int $deadline, bool $writing): void
    {
        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('scanner_unavailable');
        }
        $read = $writing ? [] : [$socket];
        $write = $writing ? [$socket] : [];
        $except = [];
        $ready = @stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1000));
        if ($ready === false || $ready === 0 || hrtime(true) >= $deadline) {
            throw new RuntimeException('scanner_unavailable');
        }
    }
}
