<?php

namespace App\Http\Controllers\Web;

use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Projects\Actions\CreateProject;
use App\Domains\Projects\Enums\EnvironmentType;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
                ->with('environments')
                ->latest()
                ->get()
                ->map(fn (Project $project): array => $this->projectPayload($project))
                ->all(),
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

    public function show(Request $request, Project $project, OrganizationContext $organizationContext): Response
    {
        $organizationContext->require($request);
        Gate::authorize('view', $project);

        $project->load('environments');

        return Inertia::render('Projects/Show', [
            'project' => $this->projectPayload($project),
            'environmentTypes' => collect(EnvironmentType::cases())
                ->map(fn (EnvironmentType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])
                ->all(),
            'canManage' => Gate::allows('update', $project),
            'canDelete' => Gate::allows('delete', $project),
        ]);
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
            'environments' => $project->environments
                ->map(fn (Environment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'slug' => $environment->slug,
                    'type' => $environment->type->value,
                    'type_label' => $environment->type->label(),
                    'created_at' => $environment->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}
