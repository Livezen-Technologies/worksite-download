<?php

namespace App\Libraries\Scanner;

/**
 * clamd INSTREAM over a unix/tcp socket (Q6: ClamAV). Streams the object —
 * the file is never written anywhere executable and never executed.
 */
final class ClamAvScanner implements MalwareScannerInterface
{
    public function __construct(private readonly string $socket)
    {
    }

    public function scan($stream): array
    {
        $sock = @stream_socket_client($this->socket, $errno, $errstr, 5);
        if ($sock === false) {
            return ['status' => 'ERROR', 'detail' => "clamd unreachable: {$errstr}"];
        }
        stream_set_timeout($sock, 300);
        fwrite($sock, "zINSTREAM\0");
        while (! feof($stream)) {
            $chunk = fread($stream, 1 << 20);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($sock, pack('N', strlen($chunk)) . $chunk);
        }
        fwrite($sock, pack('N', 0));
        $reply = trim((string) stream_get_contents($sock), "\0\n ");
        fclose($sock);
        if (str_ends_with($reply, 'OK')) {
            return ['status' => 'CLEAN', 'detail' => 'ClamAV: clean'];
        }
        if (str_contains($reply, 'FOUND')) {
            return ['status' => 'INFECTED', 'detail' => 'ClamAV: ' . $reply];
        }

        return ['status' => 'ERROR', 'detail' => 'ClamAV: ' . $reply];
    }
}
