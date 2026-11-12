<?php

namespace App\Http\Controllers\Web;

use App\Domains\Applications\Actions\CreateApplication;
use App\Domains\Applications\Actions\DeleteApplication;
use App\Domains\Applications\Actions\DeployApplication;
use App\Domains\Applications\Actions\RefreshApplication;
use App\Domains\Applications\Actions\RollbackApplication;
use App\Domains\Applications\Actions\StartApplication;
use App\Domains\Applications\Actions\StopApplication;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Applications\Presenters\ApplicationPresenter;
use App\Domains\Applications\Support\ApplicationImage;
use App\Domains\Infrastructure\Enums\ServerStatus;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    public function index(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        ApplicationPresenter $presenter,
    ): Response {
        $organization = $organizationContext->require($request);
        Gate::authorize('view', $project);

        $project->loadMissing('environments');

        $applications = $project->applications()
            ->with(['project', 'environment', 'server', 'latestDeployment'])
            ->latest()
            ->get()
            ->map(fn (Application $application): array => $presenter->toArray($application, $request->user()))
            ->values()
            ->all();

        return Inertia::render('Applications/Index', [
            'project' => $this->projectPayload($project),
            'applications' => $applications,
            'servers' => $this->servers($organization),
            'canCreate' => Gate::allows('create', [Application::class, $project]),
        ]);
    }

    public function store(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        CreateApplication $createApplication,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('view', $project);
        Gate::authorize('create', [Application::class, $project]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'image' => ['required', 'string', 'max:255', ApplicationImage::rule()],
            'container_port' => ['required', 'integer', 'between:1,65535'],
            'health_path' => ['nullable', 'string', 'max:255', 'regex:/^\/[^\s]*$/'],
            'environment_id' => ['required', 'uuid'],
            'server_id' => ['required', 'uuid'],
        ]);

        $environment = Environment::query()
            ->where('organization_id', $organization->id)
            ->where('project_id', $project->id)
            ->findOrFail($validated['environment_id']);

        $server = $organization->servers()->findOrFail($validated['server_id']);

        $application = $createApplication->execute(
            $project,
            $environment,
            $server,
            trim($validated['name']),
            trim($validated['image']),
            (int) $validated['container_port'],
            $this->healthPath($validated['health_path'] ?? null),
            $request->user(),
        );

        return redirect()
            ->route('applications.show', [$project, $application])
            ->with('success', "{$application->name} is being deployed.");
    }

    public function show(
        Request $request,
        Project $project,
        Application $application,
        OrganizationContext $organizationContext,
        ApplicationPresenter $presenter,
    ): Response {
        $this->authorizeApplication($request, $project, $application, $organizationContext);

        $application->load([
            'project',
            'environment',
            'server',
            'latestDeployment',
            'deployments' => fn ($query) => $query->latest()->with('events'),
        ]);

        return Inertia::render('Applications/Show', [
            'project' => $this->projectPayload($project),
            'application' => $presenter->toArray($application, $request->user(), true),
        ]);
    }

    public function deploy(
        Request $request,
        Project $project,
        Application $application,
        OrganizationContext $organizationContext,
        DeployApplication $deployApplication,
    ): RedirectResponse {
        $this->authorizeApplication($request, $project, $application, $organizationContext);
        Gate::authorize('deploy', $application);

        $deployApplication->execute($application, $request->user());

        return back()->with('success', "{$application->name} deployment requested.");
    }

    public function rollback(
        Request $request,
        Project $project,
        Application $application,
        Deployment $deployment,
        OrganizationContext $organizationContext,
        RollbackApplication $rollbackApplication,
    ): RedirectResponse {
        $this->authorizeApplication($request, $project, $application, $organizationContext);
        Gate::authorize('rollback', $application);

        abort_unless($deployment->application_id === $application->id, 404);

        $rollbackApplication->execute($application, $deployment, $request->user());

        return back()->with('success', "Rollback to {$deployment->image} requested.");
    }

    public function start(
        Request $request,
        Project $project,
        Application $application,
        OrganizationContext $organizationContext,
        StartApplication $startApplication,
    ): RedirectResponse {
        $this->authorizeApplication($request, $project, $application, $organizationContext);
        Gate::authorize('start', $application);

        $startApplication->execute($application, $request->user());

        return back()->with('success', "{$application->name} is starting.");
    }

    public function stop(
        Request $request,
        Project $project,
        Application $application,
        OrganizationContext $organizationContext,
        StopApplication $stopApplication,
    ): RedirectResponse {
        $this->authorizeApplication($request, $project, $application, $organizationContext);
        Gate::authorize('stop', $application);

        $stopApplication->execute($application, $request->user());

        return back()->with('success', "{$application->name} is stopping.");
    }

    public function refresh(
        Request $request,
        Project $project,
        Application $application,
        OrganizationContext $organizationContext,
        RefreshApplication $refreshApplication,
    ): RedirectResponse {
        $this->authorizeApplication($request, $project, $application, $organizationContext);
        Gate::authorize('refresh', $application);

        $refreshApplication->execute($application, $request->user());

        return back()->with('success', 'Application state refresh requested.');
    }

    public function destroy(
        Request $request,
        Project $project,
        Application $application,
        OrganizationContext $organizationContext,
        DeleteApplication $deleteApplication,
    ): RedirectResponse {
        $this->authorizeApplication($request, $project, $application, $organizationContext);
        Gate::authorize('delete', $application);

        $deleteApplication->execute($application, $request->user());

        return redirect()
            ->route('applications.index', $project)
            ->with('success', "{$application->name} is being deleted.");
    }

    private function authorizeApplication(
        Request $request,
        Project $project,
        Application $application,
        OrganizationContext $organizationContext,
    ): void {
        $organization = $organizationContext->require($request);

        abort_unless(
            $application->organization_id === $organization->id && $application->project_id === $project->id,
            404,
        );

        Gate::authorize('view', $project);
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
            'environments' => $project->environments
                ->map(fn (Environment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'slug' => $environment->slug,
                    'type' => $environment->type->value,
                    'type_label' => $environment->type->label(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function servers(Organization $organization): array
    {
        return $organization->servers()
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
            ->values()
            ->all();
    }

    private function healthPath(?string $healthPath): ?string
    {
        $healthPath = trim((string) $healthPath);

        return $healthPath === '' ? null : $healthPath;
    }
}
