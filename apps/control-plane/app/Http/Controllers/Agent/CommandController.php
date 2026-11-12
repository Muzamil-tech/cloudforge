<?php

namespace App\Http\Controllers\Agent;

use App\Domains\Infrastructure\Actions\RotateServerCertificate;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Operations\Services\AgentCommandService;
use App\Domains\Resources\Actions\HandleResourceCommandResult;
use App\Http\Requests\Agent\CommandResultRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CommandController extends AgentController
{
    public function next(Request $request, AgentCommandService $commands): JsonResponse|Response
    {
        $server = $this->agentServer($request);
        $wait = min(
            max(0, $request->integer('wait', 0)),
            (int) config('cloudforge.agent.max_poll_wait_seconds', 25),
        );
        $deadline = now()->addSeconds($wait);

        do {
            $command = $commands->nextFor($server);

            if ($command !== null) {
                return response()->json([
                    'command' => [
                        'id' => $command->command_id,
                        'operation' => $command->operation->value,
                        'payload' => $command->payload ?? [],
                        'issued_at' => $command->issued_at->toIso8601String(),
                        'expires_at' => $command->expires_at->toIso8601String(),
                        'attempt' => $command->attempts,
                    ],
                ], 200, ['Cache-Control' => 'no-store']);
            }

            if (now()->gte($deadline)) {
                break;
            }

            sleep(1);
        } while (true);

        return response()->noContent();
    }

    public function acknowledge(Request $request, string $command, AgentCommandService $commands): JsonResponse
    {
        $server = $this->agentServer($request);
        $agentCommand = $this->findCommand($server->id, $command);
        $agentCommand = $commands->acknowledge($agentCommand, $server);

        return response()->json([
            'status' => $agentCommand->status->value,
            'command_id' => $agentCommand->command_id,
        ]);
    }

    public function result(
        CommandResultRequest $request,
        string $command,
        AgentCommandService $commands,
        HandleResourceCommandResult $resourceResults,
    ): JsonResponse {
        $server = $this->agentServer($request);
        $agentCommand = $this->findCommand($server->id, $command);
        $validated = $request->validated();
        $succeeded = $validated['status'] === 'completed';

        $agentCommand = $commands->complete(
            $agentCommand,
            $server,
            $succeeded,
            $validated['result'] ?? [],
            $validated['error_message'] ?? null,
        );

        $resourceResults->handle(
            $agentCommand,
            $succeeded,
            $validated['result'] ?? [],
            $validated['error_message'] ?? null,
        );

        return response()->json([
            'status' => $agentCommand->status->value,
            'command_id' => $agentCommand->command_id,
        ]);
    }

    public function rotateCertificate(Request $request, RotateServerCertificate $rotate): JsonResponse
    {
        $validated = $request->validate([
            'csr' => ['required', 'string', 'max:16384'],
        ]);

        $result = $rotate->execute($this->agentServer($request), $validated['csr']);

        return response()->json([
            'certificate' => [
                'pem' => $result['certificate']->certificate_pem,
                'serial' => $result['certificate']->serial,
                'fingerprint_sha256' => $result['certificate']->fingerprint_sha256,
                'expires_at' => $result['certificate']->expires_at->toIso8601String(),
            ],
            'ca_certificate' => $result['ca_certificate'],
        ]);
    }

    private function findCommand(string $serverId, string $commandId): AgentCommand
    {
        return AgentCommand::query()
            ->where('server_id', $serverId)
            ->where('command_id', $commandId)
            ->firstOrFail();
    }
}
