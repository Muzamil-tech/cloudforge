<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Infrastructure\Actions\IssueEnrollmentToken;
use App\Domains\Infrastructure\Actions\RevokeServerCertificates;
use App\Domains\Infrastructure\Enums\ServerRoleType;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Models\ServerRole;
use App\Domains\Infrastructure\Services\AgentInstallCommand;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AgentCommandService;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateOrganizationToken;
use App\Http\Resources\AgentCommandResource;
use App\Http\Resources\ServerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $servers = $this->organization($request)
            ->servers()
            ->with(['roles', 'capabilities'])
            ->latest()
            ->paginate(50);

        return ServerResource::collection($servers);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('servers', 'name')
                    ->where('organization_id', $organization->id)
                    ->whereNull('deleted_at'),
            ],
            'hostname' => ['required', 'string', 'max:255'],
            'public_ip' => ['nullable', 'ip'],
            'private_ip' => ['nullable', 'ip'],
            'region' => ['nullable', 'string', 'max:120'],
            'roles' => ['nullable', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(ServerRoleType::values())],
        ], [
            'name.unique' => 'This organization already has a server with that name.',
        ]);

        $server = $organization->servers()->create([
            'name' => $validated['name'],
            'hostname' => $validated['hostname'],
            'public_ip' => $validated['public_ip'] ?? null,
            'private_ip' => $validated['private_ip'] ?? null,
            'region' => $validated['region'] ?? null,
            'status' => ServerStatus::InstallationPending,
        ]);

        foreach ($validated['roles'] ?? [ServerRoleType::General->value] as $role) {
            ServerRole::query()->create(['server_id' => $server->id, 'role' => $role]);
        }

        $auditLogger->record(AuditAction::ServerCreated, $organization, $server, ['name' => $server->name], $request->user());

        return (new ServerResource($server->load(['roles', 'capabilities'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $server): JsonResponse
    {
        $model = $this->findServer($request, $server);

        $model->load(['roles', 'capabilities', 'metrics' => fn ($query) => $query->latest('recorded_at')->limit(120)]);

        return response()->json([
            'data' => new ServerResource($model),
            'certificates' => $model->certificates()->latest('issued_at')->get()->map(fn ($certificate) => [
                'serial' => $certificate->serial,
                'fingerprint_sha256' => $certificate->fingerprint_sha256,
                'issued_at' => $certificate->issued_at->toIso8601String(),
                'expires_at' => $certificate->expires_at->toIso8601String(),
                'revoked_at' => $certificate->revoked_at?->toIso8601String(),
                'revocation_reason' => $certificate->revocation_reason,
            ]),
            'commands' => AgentCommandResource::collection(
                $model->commands()->latest('issued_at')->limit(25)->get(),
            ),
        ]);
    }

    public function update(Request $request, string $server, AuditLogger $auditLogger): ServerResource
    {
        $organization = $this->organization($request);
        $model = $this->findServer($request, $server);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('servers', 'name')
                    ->ignore($model->id)
                    ->where('organization_id', $organization->id)
                    ->whereNull('deleted_at'),
            ],
            'hostname' => ['sometimes', 'required', 'string', 'max:255'],
            'public_ip' => ['nullable', 'ip'],
            'private_ip' => ['nullable', 'ip'],
            'region' => ['nullable', 'string', 'max:120'],
        ], [
            'name.unique' => 'This organization already has a server with that name.',
        ]);

        $model->fill($validated)->save();

        $auditLogger->record(
            AuditAction::ServerUpdated,
            $this->organization($request),
            $model,
            ['changed' => array_keys($validated)],
            $request->user(),
        );

        return new ServerResource($model->load(['roles', 'capabilities']));
    }

    public function destroy(Request $request, string $server, AuditLogger $auditLogger): JsonResponse
    {
        $model = $this->findServer($request, $server);
        $organization = $this->organization($request);

        $auditLogger->record(AuditAction::ServerDeleted, $organization, $model, ['name' => $model->name], $request->user());

        $model->certificates()->whereNull('revoked_at')->update([
            'revoked_at' => now(),
            'revocation_reason' => 'server_deleted',
        ]);

        $model->delete();

        return response()->json(['deleted' => true]);
    }

    public function issueEnrollment(
        Request $request,
        string $server,
        IssueEnrollmentToken $issueEnrollmentToken,
        AgentInstallCommand $installCommand,
    ): JsonResponse {
        $model = $this->findServer($request, $server);
        $result = $issueEnrollmentToken->execute($model, $request->user());

        return response()->json([
            'enrollment_token' => $result['token'],
            'expires_at' => $result['enrollment']->expires_at->toIso8601String(),
            'install_command' => $installCommand->for($model, $result['token']),
        ], 201);
    }

    public function revokeCertificates(
        Request $request,
        string $server,
        RevokeServerCertificates $revokeCertificates,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $model = $this->findServer($request, $server);
        $count = $revokeCertificates->execute($model, $validated['reason'], $request->user());

        return response()->json(['revoked' => $count]);
    }

    public function commands(Request $request, string $server): AnonymousResourceCollection
    {
        $model = $this->findServer($request, $server);

        return AgentCommandResource::collection($model->commands()->latest('issued_at')->paginate(50));
    }

    public function issueCommand(
        Request $request,
        string $server,
        AgentCommandService $commands,
    ): JsonResponse {
        $validated = $request->validate([
            'operation' => ['required', 'string', Rule::in(AgentOperation::values())],
            'payload' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        $model = $this->findServer($request, $server);
        $command = $commands->issue(
            $model,
            AgentOperation::from($validated['operation']),
            $validated['payload'] ?? [],
            $request->user(),
            $validated['idempotency_key'] ?? $request->header('Idempotency-Key'),
        );

        return (new AgentCommandResource($command))->response()->setStatusCode(202);
    }

    public function cancelCommand(
        Request $request,
        string $server,
        string $command,
        AgentCommandService $commands,
    ): AgentCommandResource {
        $model = $this->findServer($request, $server);
        $agentCommand = $model->commands()->where('command_id', $command)->firstOrFail();

        return new AgentCommandResource($commands->cancel($agentCommand, $model, $request->user()));
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get(AuthenticateOrganizationToken::ORGANIZATION_ATTRIBUTE);
        abort_if($organization === null, 401);

        return $organization;
    }

    private function findServer(Request $request, string $serverId): Server
    {
        return $this->organization($request)
            ->servers()
            ->with(['roles', 'capabilities'])
            ->findOrFail($serverId);
    }
}
