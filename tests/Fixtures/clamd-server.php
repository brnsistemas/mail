<?php

// Local, disposable protocol peer. No real signature database or external access.
$scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (! $server) {
    exit(1);
}
echo stream_socket_get_name($server, false)."\n";
flush();
$peer = stream_socket_accept($server, 5);
if (! $peer) {
    exit(2);
}
stream_set_timeout($peer, 5);
if ($scenario['no_read'] ?? false) {
    usleep(2_000_000);
    exit(0);
}
$read = function (int $length) use ($peer): string {
    $bytes = '';
    while (strlen($bytes) < $length) {
        $part = fread($peer, $length - strlen($bytes));
        if ($part === false || $part === '') {
            exit(3);
        }
        $bytes .= $part;
    }

    return $bytes;
};
if ($read(10) !== "zINSTREAM\0") {
    exit(4);
}
$received = '';
while (($length = unpack('N', $read(4))[1]) !== 0) {
    if ($length > 8192 || strlen($received) + $length > 10 * 1024 * 1024) {
        exit(5);
    }
    $received .= $read($length);
}
if (hash('sha256', $received) !== $scenario['sha256']) {
    exit(6);
}
foreach ($scenario['parts'] as $part) {
    usleep($scenario['delay_us'] ?? 0);
    if (@fwrite($peer, base64_decode($part, true)) === false) {
        break;
    }
    fflush($peer);
}
fclose($peer);
fclose($server);
