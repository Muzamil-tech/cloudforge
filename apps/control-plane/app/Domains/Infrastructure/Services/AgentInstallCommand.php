<?php

namespace App\Domains\Infrastructure\Services;

use App\Domains\Infrastructure\Models\Server;

class AgentInstallCommand
{
    public function for(Server $server, string $token): string
    {
        $scriptUrl = (string) config('cloudforge.install.script_url');
        $controlPlaneUrl = rtrim((string) config('app.url'), '/');

        return sprintf(
            'curl -fsSL %s | sudo env CLOUDFORGE_ENROLLMENT_TOKEN=%s CLOUDFORGE_CONTROL_PLANE_URL=%s CLOUDFORGE_SERVER_ID=%s sh',
            escapeshellarg($scriptUrl),
            escapeshellarg($token),
            escapeshellarg($controlPlaneUrl),
            escapeshellarg($server->id),
        );
    }
}
