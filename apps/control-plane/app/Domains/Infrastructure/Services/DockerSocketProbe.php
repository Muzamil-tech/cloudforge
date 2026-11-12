<?php

namespace App\Domains\Infrastructure\Services;

use Illuminate\Support\Facades\Log;

class DockerSocketProbe
{
    public function __construct(
        private readonly string $socketPath,
        private readonly float $timeoutSeconds = 2.0,
    ) {}

    /**
     * Read the Docker Engine version from the local Unix socket.
     *
     * Only the fixed /version endpoint is requested. The agent protocol is the
     * only path allowed to perform Docker mutations.
     */
    public function version(): ?string
    {
        if (! function_exists('stream_socket_client') || ! file_exists($this->socketPath)) {
            return null;
        }

        $stream = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
        );

        if ($stream === false) {
            return null;
        }

        stream_set_timeout($stream, (int) ceil($this->timeoutSeconds));

        $request = "GET /version HTTP/1.1\r\nHost: docker\r\nAccept: application/json\r\nConnection: close\r\n\r\n";

        if (@fwrite($stream, $request) === false) {
            fclose($stream);

            return null;
        }

        $response = stream_get_contents($stream);
        fclose($stream);

        if (! is_string($response) || $response === '') {
            return null;
        }

        $version = self::parseVersion($response);

        if ($version === null) {
            Log::debug('Docker socket responded without a usable version payload.', [
                'socket' => $this->socketPath,
            ]);
        }

        return $version;
    }

    /**
     * Parse a raw Docker HTTP response and return the Engine version.
     */
    public static function parseVersion(string $response): ?string
    {
        $parts = preg_split("/\r\n\r\n/", $response, 2);

        if ($parts === false || count($parts) !== 2) {
            return null;
        }

        [$headers, $body] = $parts;

        if (! preg_match('#^HTTP/\d(?:\.\d)?\s+200\b#', $headers)) {
            return null;
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        $version = $payload['Version'] ?? $payload['version'] ?? null;

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }
}
