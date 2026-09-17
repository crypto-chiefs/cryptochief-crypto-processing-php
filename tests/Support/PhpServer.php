<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests\Support;

/**
 * PHP built-in web server running one router script on 127.0.0.1, for tests that need a
 * real HTTP round trip.
 */
final class PhpServer
{
    /** @var resource */
    private $process;

    /**
     * @param resource $process
     */
    private function __construct($process, public readonly string $url, private readonly string $logFile)
    {
        $this->process = $process;
    }

    /**
     * @param array<string, string> $env
     */
    public static function start(string $router, array $env = []): self
    {
        $port = self::freePort();
        $logFile = tempnam(sys_get_temp_dir(), 'cc-php-server-');
        if ($logFile === false) {
            throw new \RuntimeException('cannot create server log file');
        }

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
            dirname($router),
            $env + getenv(),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start php -S');
        }
        fclose($pipes[0]);

        $server = new self($process, 'http://127.0.0.1:' . $port, $logFile);
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                return $server;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        $log = (string) file_get_contents($logFile);
        $server->stop();
        throw new \RuntimeException('php -S did not start: ' . $log);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    public function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException('cannot allocate a port: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
