<?php

namespace App\Http\Controllers\Web;

use App\Domains\Infrastructure\Actions\ChangeServerState;
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
use App\Http\Controllers\Controller;
use App\Http\Resources\AgentCommandResource;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(Request $request, OrganizationContext $organizationContext): Response
    {
        $organization = $organizationContext->require($request);

        $servers = $organization->servers()
            ->with(['roles', 'capabilities'])
            ->orderByDesc('last_heartbeat_at')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Server $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'public_ip' => $server->public_ip,
                'region' => $server->region,
                'status' => $server->status->value,
                'status_label' => $server->status->label(),
                'tone' => $server->status->tone(),
                'roles' => $server->roleNames(),
                'docker_version' => $server->docker_version,
                'agent_version' => $server->agent_version,
                'is_bootstrap' => $server->isBootstrap(),
                'claimed_at' => $server->claimed_at?->toIso8601String(),
                'last_heartbeat_at' => $server->last_heartbeat_at?->toIso8601String(),
                'created_at' => $server->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Servers/Index', [
            'servers' => $servers,
            'canManage' => $organizationContext->role($request)?->canManageInfrastructure() ?? false,
        ]);
    }

    public function create(OrganizationContext $organizationContext, Request $request): Response
    {
        $organization = $organizationContext->require($request);
        abort_unless($organizationContext->role($request)?->canManageInfrastructure(), 403);

        return Inertia::render('Servers/Create', [
            'roles' => collect(ServerRoleType::cases())
                ->map(fn (ServerRoleType $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ])
                ->all(),
            'regions' => ['fsn1', 'nbg1', 'hel1', 'ash', 'hil', 'sin'],
        ]);
    }

    public function store(
        Request $request,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        abort_unless($organizationContext->role($request)?->canManageInfrastructure(), 403);

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
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(ServerRoleType::values())],
        ], [
            'name.unique' => 'This organization already has a server with that name.',
        ]);

        $server = DB::transaction(function () use ($organization, $validated, $request, $auditLogger): Server {
            $server = $organization->servers()->create([
                'name' => $validated['name'],
                'hostname' => $validated['hostname'],
                'public_ip' => $validated['public_ip'] ?? null,
                'private_ip' => $validated['private_ip'] ?? null,
                'region' => $validated['region'] ?? null,
                'status' => ServerStatus::InstallationPending,
                'metadata' => [],
            ]);

            foreach (array_unique($validated['roles']) as $role) {
                ServerRole::query()->create([
                    'server_id' => $server->id,
                    'role' => $role,
                    'created_at' => now(),
                ]);
            }

            $auditLogger->record(
                AuditAction::ServerCreated,
                $organization,
                $server,
                ['name' => $server->name, 'roles' => array_values($validated['roles'])],
                $request->user(),
            );

            return $server;
        });

        return redirect()->route('servers.show', $server)
            ->with('success', 'Server registered. Issue an enrollment token to install the agent.');
    }

    public function show(
        Request $request,
        Server $server,
        OrganizationContext $organizationContext,
    ): Response {
        $organizationContext->require($request);
        Gate::authorize('view', $server);

        $server->load(['roles', 'capabilities']);

        $metrics = $server->metrics()
            ->where('recorded_at', '>=', now()->subHours(6))
            ->orderBy('recorded_at')
            ->limit(180)
            ->get();

        $certificates = $server->certificates()->latest('issued_at')->limit(10)->get();
        $commands = $server->commands()->latest('issued_at')->limit(20)->get();

        $activeEnrollment = $server->enrollments()
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        $metadata = $server->metadata ?? [];

        return Inertia::render('Servers/Show', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'public_ip' => $server->public_ip,
                'private_ip' => $server->private_ip,
                'region' => $server->region,
                'operating_system' => $server->operating_system,
                'architecture' => $server->architecture,
                'cpu_cores' => $server->cpu_cores,
                'total_memory_bytes' => $server->total_memory_bytes,
                'total_disk_bytes' => $server->total_disk_bytes,
                'docker_version' => $server->docker_version,
                'agent_version' => $server->agent_version,
                'status' => $server->status->value,
                'status_label' => $server->status->label(),
                'tone' => $server->status->tone(),
                'roles' => $server->roleNames(),
                'is_bootstrap' => $server->isBootstrap(),
                'claimed_at' => $server->claimed_at?->toIso8601String(),
                'capabilities' => $server->capabilities->map(fn ($capability): array => [
                    'name' => $capability->capability,
                    'version' => $capability->version,
                    'metadata' => $capability->metadata,
                ])->all(),
                'last_heartbeat_at' => $server->last_heartbeat_at?->toIso8601String(),
                'last_inventory_at' => $server->last_inventory_at?->toIso8601String(),
                'created_at' => $server->created_at?->toIso8601String(),
                'last_heartbeat' => $metadata['last_heartbeat'] ?? null,
                'inventory' => $metadata['inventory'] ?? null,
            ],
            'metrics' => $metrics->map(fn ($metric): array => [
                'recorded_at' => $metric->recorded_at?->toIso8601String(),
                'cpu_usage' => $metric->cpu_usage,
                'memory_usage' => $metric->memory_usage,
                'disk_usage' => $metric->disk_usage,
                'memory_used_bytes' => $metric->memory_used_bytes,
                'disk_used_bytes' => $metric->disk_used_bytes,
                'network_in_bytes' => $metric->network_in_bytes,
                'network_out_bytes' => $metric->network_out_bytes,
            ])->all(),
            'certificates' => $certificates->map(fn ($certificate): array => [
                'id' => $certificate->id,
                'serial' => $certificate->serial,
                'fingerprint_sha256' => $certificate->fingerprint_sha256,
                'issued_at' => $certificate->issued_at->toIso8601String(),
                'expires_at' => $certificate->expires_at->toIso8601String(),
                'revoked_at' => $certificate->revoked_at?->toIso8601String(),
                'revocation_reason' => $certificate->revocation_reason,
                'is_active' => $certificate->revoked_at === null && $certificate->expires_at->isFuture(),
            ])->all(),
            'commands' => AgentCommandResource::collection($commands)->resolve(),
            'activeEnrollment' => $activeEnrollment === null ? null : [
                'expires_at' => $activeEnrollment->expires_at->toIso8601String(),
                'created_at' => $activeEnrollment->created_at?->toIso8601String(),
            ],
            'hasActiveCertificate' => $server->activeCertificate() !== null,
            'operations' => collect(AgentOperation::cases())
                ->map(fn (AgentOperation $operation): array => [
                    'value' => $operation->value,
                    'label' => $operation->label(),
                    'description' => $operation->description(),
                    'requires_operator' => $operation->requiresOperationalRole(),
                ])
                ->all(),
            'stateActions' => collect($server->status->allowedTransitions())
                ->filter(fn (ServerStatus $status): bool => in_array($status, [
                    ServerStatus::Active,
                    ServerStatus::Draining,
                    ServerStatus::Maintenance,
                ], true))
                ->map(fn (ServerStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values()
                ->all(),
            'can' => [
                'manageInfrastructure' => $organizationContext->role($request)?->canManageInfrastructure() ?? false,
                'operate' => $organizationContext->role($request)?->canOperate() ?? false,
                'deploy' => $organizationContext->role($request)?->canDeploy() ?? false,
            ],
        ]);
    }

    public function destroy(
        Request $request,
        Server $server,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('delete', $server);

        $auditLogger->record(AuditAction::ServerDeleted, $organization, $server, ['name' => $server->name], $request->user());

        $server->certificates()->whereNull('revoked_at')->update([
            'revoked_at' => now(),
            'revocation_reason' => 'server_deleted',
        ]);

        $server->delete();

        return redirect()->route('servers.index')->with('success', "{$server->name} was removed.");
    }

    public function issueEnrollment(
        Request $request,
        Server $server,
        OrganizationContext $organizationContext,
        IssueEnrollmentToken $issueEnrollmentToken,
        AgentInstallCommand $installCommand,
    ): RedirectResponse {
        $organizationContext->require($request);
        Gate::authorize('issueEnrollment', $server);

        $result = $issueEnrollmentToken->execute($server, $request->user());

        return back()
            ->with('success', 'Enrollment token issued. Run the installation command on the server.')
            ->with('install_command', $installCommand->for($server, $result['token']))
            ->with('enrollment_token', $result['token'])
            ->with('enrollment_expires_at', $result['enrollment']->expires_at->toIso8601String());
    }

    public function revokeCertificates(
        Request $request,
        Server $server,
        OrganizationContext $organizationContext,
        RevokeServerCertificates $revokeCertificates,
    ): RedirectResponse {
        $organizationContext->require($request);
        Gate::authorize('revokeCertificates', $server);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $count = $revokeCertificates->execute($server, $validated['reason'], $request->user());

        return back()->with('success', $count === 0
            ? 'There were no active certificates to revoke.'
            : "{$count} certificate(s) revoked. The agent must be enrolled again.");
    }

    public function changeState(
        Request $request,
        Server $server,
        OrganizationContext $organizationContext,
        ChangeServerState $changeServerState,
    ): RedirectResponse {
        $organizationContext->require($request);
        Gate::authorize('changeState', $server);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                ServerStatus::Active->value,
                ServerStatus::Draining->value,
                ServerStatus::Maintenance->value,
            ])],
            'confirm' => ['accepted'],
        ]);

        $target = ServerStatus::from($validated['status']);

        $changeServerState->execute($server, $target, $request->user());

        return back()->with('success', "Server state changed to {$target->label()}.");
    }

    public function issueCommand(
        Request $request,
        Server $server,
        OrganizationContext $organizationContext,
        AgentCommandService $commands,
    ): RedirectResponse {
        $organizationContext->require($request);

        $validated = $request->validate([
            'operation' => ['required', 'string', Rule::in(AgentOperation::values())],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $operation = AgentOperation::from($validated['operation']);

        Gate::authorize('executeOperation', [$server, $operation]);

        $payload = array_filter([
            'reason' => $validated['reason'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');

        $commands->issue($server, $operation, $payload, $request->user());

        return back()->with('success', "{$operation->label()} command queued.");
    }

    public function cancelCommand(
        Request $request,
        Server $server,
        string $command,
        OrganizationContext $organizationContext,
        AgentCommandService $commands,
    ): RedirectResponse {
        $organizationContext->require($request);

        $agentCommand = $server->commands()->where('command_id', $command)->firstOrFail();

        Gate::authorize('cancel', $agentCommand);

        $commands->cancel($agentCommand, $server, $request->user());

        return back()->with('success', 'Command cancelled.');
    }
}
