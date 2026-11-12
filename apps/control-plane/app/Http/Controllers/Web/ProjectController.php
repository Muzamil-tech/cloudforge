<?php

namespace App\Http\Controllers\Web;

use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AuditLog;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Projects\Actions\CreateProject;
use App\Domains\Projects\Enums\EnvironmentType;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Enums\ResourceType;
use App\Domains\Resources\Models\Resource;
use App\Domains\Resources\Presenters\ResourcePresenter;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request, OrganizationContext $organizationContext): Response
    {
        $organization = $organizationContext->require($request);

        return Inertia::render('Projects/Index', [
            'projects' => $organization->projects()
                ->with(['environments', 'resources', 'applications'])
                ->latest()
                ->get()
                ->map(fn (Project $project): array => $this->projectPayload($project))
                ->all(),
            'summary' => [
                'projects' => $organization->projects()->count(),
                'databases' => $organization->resources()->count(),
                'applications' => $organization->applications()->count(),
                'environments' => $organization->projects()->withCount('environments')->get()->sum('environments_count'),
                'active_databases' => $organization->resources()
                    ->where('status', ResourceStatus::Active)
                    ->count(),
                'active_applications' => $organization->applications()
                    ->where('status', ApplicationStatus::Active)
                    ->count(),
            ],
            'canCreate' => Gate::allows('create', [Project::class, $organization]),
            'canManage' => $organizationContext->role($request)?->canDeploy() ?? false,
        ]);
    }

    public function store(
        Request $request,
        OrganizationContext $organizationContext,
        CreateProject $createProject,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('create', [Project::class, $organization]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = $createProject->execute($organization, $request->user(), $validated);

        return redirect()->route('projects.show', $project)
            ->with('success', "{$project->name} was created with a production environment.");
    }

    public function show(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        ResourcePresenter $resourcePresenter,
    ): Response {
        return $this->workspace($request, $project, $organizationContext, $resourcePresenter, 'overview');
    }

    public function databases(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        ResourcePresenter $resourcePresenter,
    ): Response {
        return $this->workspace($request, $project, $organizationContext, $resourcePresenter, 'databases');
    }

    public function environmentsTab(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        ResourcePresenter $resourcePresenter,
    ): Response {
        return $this->workspace($request, $project, $organizationContext, $resourcePresenter, 'environments');
    }

    public function activity(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        ResourcePresenter $resourcePresenter,
    ): Response {
        return $this->workspace($request, $project, $organizationContext, $resourcePresenter, 'activity');
    }

    public function settings(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        ResourcePresenter $resourcePresenter,
    ): Response {
        return $this->workspace($request, $project, $organizationContext, $resourcePresenter, 'settings');
    }

    private function workspace(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        ResourcePresenter $resourcePresenter,
        string $tab,
    ): Response {
        $organization = $organizationContext->require($request);
        Gate::authorize('view', $project);

        $project->load(['environments', 'resources.project', 'resources.environment', 'resources.server', 'applications.environment', 'applications.server', 'applications.latestDeployment']);

        $servers = $organization->servers()
            ->whereIn('status', [ServerStatus::Active, ServerStatus::Ready])
            ->orderBy('name')
            ->get()
            ->map(fn ($server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status->value,
                'status_label' => $server->status->label(),
                'docker_version' => $server->docker_version,
            ])
            ->all();

        $resources = $project->resources;
        $applications = $project->applications;
        $activity = $this->projectActivity($project, $tab === 'activity' ? 100 : 8);

        $attention = $resources
            ->where('status', ResourceStatus::Failed)
            ->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'tone' => 'danger',
                'title' => "{$resource->name} failed",
                'description' => $resource->last_error ?: 'The managed resource needs attention.',
                'href' => route('projects.databases', $project),
            ])
            ->values()
            ->all();

        $applicationAttention = $applications
            ->where('status', ApplicationStatus::Failed)
            ->map(fn (Application $application): array => [
                'id' => $application->id,
                'tone' => 'danger',
                'title' => "{$application->name} failed",
                'description' => $application->last_error ?: 'The application deployment needs attention.',
                'href' => route('applications.show', [$project, $application]),
            ])
            ->values()
            ->all();

        $attention = array_merge($attention, $applicationAttention);

        $stats = [
            'databases' => $resources->count(),
            'active_databases' => $resources->where('status', ResourceStatus::Active)->count(),
            'failed_databases' => $resources->where('status', ResourceStatus::Failed)->count(),
            'applications' => $applications->count(),
            'active_applications' => $applications->where('status', ApplicationStatus::Active)->count(),
            'failed_applications' => $applications->where('status', ApplicationStatus::Failed)->count(),
            'environments' => $project->environments->count(),
            'servers' => $resources->pluck('server_id')
                ->merge($applications->pluck('server_id'))
                ->unique()
                ->count(),
            'attention' => count($attention),
        ];

        return Inertia::render('Projects/Show', [
            'tab' => $tab,
            'project' => $this->projectPayload($project),
            'resources' => $resources
                ->map(fn (Resource $resource): array => $resourcePresenter->toArray($resource, $request->user()))
                ->values()
                ->all(),
            'applications' => $applications
                ->map(fn (Application $application): array => [
                    'id' => $application->id,
                    'name' => $application->name,
                    'image' => $application->image,
                    'status' => $application->status->value,
                    'status_label' => $application->status->label(),
                    'status_tone' => $application->status->tone(),
                    'environment' => [
                        'name' => $application->environment->name,
                        'type_label' => $application->environment->type->label(),
                    ],
                    'server' => [
                        'name' => $application->server->name,
                    ],
                    'host_port' => $application->host_port,
                    'last_error' => $application->last_error,
                    'latest_deployment' => $application->latestDeployment
                        ? [
                            'id' => $application->latestDeployment->id,
                            'status' => $application->latestDeployment->status->value,
                            'status_label' => $application->latestDeployment->status->label(),
                            'status_tone' => $application->latestDeployment->status->tone(),
                            'image' => $application->latestDeployment->image,
                        ]
                        : null,
                ])
                ->values()
                ->all(),
            'servers' => $servers,
            'resourceTypes' => collect(ResourceType::cases())
                ->map(fn (ResourceType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'image' => $type->image(),
                    'port' => $type->port(),
                ])
                ->all(),
            'environmentTypes' => collect(EnvironmentType::cases())
                ->map(fn (EnvironmentType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])
                ->all(),
            'activity' => $activity,
            'stats' => $stats,
            'attention' => $attention,
            'canManage' => Gate::allows('update', $project),
            'canDelete' => Gate::allows('delete', $project),
            'canCreateResource' => Gate::allows('create', [Resource::class, $project]),
            'canCreateApplication' => Gate::allows('create', [Application::class, $project]),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectActivity(Project $project, int $limit): array
    {
        $resourceIds = $project->resources->pluck('id');
        $applicationIds = $project->applications->pluck('id');
        $deploymentIds = Deployment::query()
            ->whereIn('application_id', $applicationIds)
            ->pluck('id');

        return AuditLog::query()
            ->where('organization_id', $project->organization_id)
            ->where(function ($query) use ($project, $resourceIds, $applicationIds, $deploymentIds): void {
                $query->where('resource_id', $project->id);

                if ($resourceIds->isNotEmpty()) {
                    $query->orWhereIn('resource_id', $resourceIds);
                }

                if ($applicationIds->isNotEmpty()) {
                    $query->orWhereIn('resource_id', $applicationIds);
                }

                if ($deploymentIds->isNotEmpty()) {
                    $query->orWhereIn('resource_id', $deploymentIds);
                }
            })
            ->with('actor')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => Str::headline(str_replace(['.', '_'], ' ', $log->action)),
                'actor' => $log->actor?->name ?? 'System',
                'resource_type' => $log->resource_type,
                'resource_id' => $log->resource_id,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function update(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organizationContext->require($request);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $project->fill($validated)->save();

        $auditLogger->record(
            AuditAction::ProjectUpdated,
            $project->organization,
            $project,
            ['changed' => array_keys($validated)],
            $request->user(),
        );

        return back()->with('success', 'Project updated.');
    }

    public function destroy(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organizationContext->require($request);
        Gate::authorize('delete', $project);

        if ($project->resources()->exists()) {
            return back()->with('error', 'Delete the project resources before deleting the project.');
        }

        if ($project->applications()->exists()) {
            return back()->with('error', 'Delete the project applications before deleting the project.');
        }

        $auditLogger->record(
            AuditAction::ProjectDeleted,
            $project->organization,
            $project,
            ['name' => $project->name],
            $request->user(),
        );

        $project->delete();

        return redirect()->route('projects.index')->with('success', "{$project->name} was deleted.");
    }

    public function storeEnvironment(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        CreateProject $createProject,
    ): RedirectResponse {
        $organizationContext->require($request);
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(EnvironmentType::values())],
        ]);

        $duplicate = $project->environments()
            ->whereRaw('lower(name) = ?', [strtolower($validated['name'])])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => 'This project already has an environment with that name.',
            ]);
        }

        $createProject->addEnvironment(
            $project,
            $project->organization,
            $request->user(),
            $validated['name'],
            EnvironmentType::from($validated['type']),
        );

        return back()->with('success', 'Environment added.');
    }

    public function destroyEnvironment(
        Request $request,
        Project $project,
        Environment $environment,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organizationContext->require($request);
        Gate::authorize('update', $project);

        abort_unless($environment->project_id === $project->id, 404);

        if ($environment->resources()->exists()) {
            return back()->with('error', 'Delete the environment resources before removing the environment.');
        }

        if ($environment->applications()->exists()) {
            return back()->with('error', 'Delete the environment applications before removing the environment.');
        }

        $name = $environment->name;
        $environment->delete();

        $auditLogger->record(
            AuditAction::ProjectUpdated,
            $project->organization,
            $project,
            ['removed_environment' => $name],
            $request->user(),
        );

        return back()->with('success', "{$name} was removed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'created_at' => $project->created_at?->toIso8601String(),
            'resource_count' => $project->resources->count(),
            'active_resource_count' => $project->resources->where('status', ResourceStatus::Active)->count(),
            'failed_resource_count' => $project->resources->where('status', ResourceStatus::Failed)->count(),
            'application_count' => $project->applications->count(),
            'active_application_count' => $project->applications->where('status', ApplicationStatus::Active)->count(),
            'failed_application_count' => $project->applications->where('status', ApplicationStatus::Failed)->count(),
            'environment_count' => $project->environments->count(),
            'server_count' => $project->resources->pluck('server_id')
                ->merge($project->applications->pluck('server_id'))
                ->unique()
                ->count(),
            'environments' => $project->environments
                ->map(fn (Environment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'slug' => $environment->slug,
                    'type' => $environment->type->value,
                    'type_label' => $environment->type->label(),
                    'created_at' => $environment->created_at?->toIso8601String(),
                    'resource_count' => $project->resources->where('environment_id', $environment->id)->count(),
                    'active_resource_count' => $project->resources
                        ->where('environment_id', $environment->id)
                        ->where('status', ResourceStatus::Active)
                        ->count(),
                    'application_count' => $project->applications->where('environment_id', $environment->id)->count(),
                    'active_application_count' => $project->applications
                        ->where('environment_id', $environment->id)
                        ->where('status', ApplicationStatus::Active)
                        ->count(),
                ])
                ->values()
                ->all(),
        ];
    }
}
