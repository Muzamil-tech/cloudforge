<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Enums\EnvironmentType;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateOrganizationToken;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProjectResource::collection(
            $this->organization($request)->projects()->with('environments')->latest()->paginate(50),
        );
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $organization = $this->organization($request);
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($organization, $validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        Environment::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Production',
            'slug' => 'production',
            'type' => EnvironmentType::Production,
        ]);

        $auditLogger->record(AuditAction::ProjectCreated, $organization, $project, ['name' => $project->name], $request->user());

        return (new ProjectResource($project->load('environments')))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $project): ProjectResource
    {
        return new ProjectResource($this->findProject($request, $project)->load('environments'));
    }

    public function update(Request $request, string $project, AuditLogger $auditLogger): ProjectResource
    {
        $model = $this->findProject($request, $project);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['name']) && $validated['name'] !== $model->name) {
            $validated['slug'] = $this->uniqueSlug($model->organization, $validated['name'], $model->id);
        }

        $model->fill($validated)->save();

        $auditLogger->record(
            AuditAction::ProjectUpdated,
            $this->organization($request),
            $model,
            ['changed' => array_keys($validated)],
            $request->user(),
        );

        return new ProjectResource($model->load('environments'));
    }

    public function destroy(Request $request, string $project, AuditLogger $auditLogger): JsonResponse
    {
        $model = $this->findProject($request, $project);
        $auditLogger->record(AuditAction::ProjectDeleted, $this->organization($request), $model, ['name' => $model->name], $request->user());
        $model->delete();

        return response()->json(['deleted' => true]);
    }

    public function storeEnvironment(Request $request, string $project): JsonResponse
    {
        $model = $this->findProject($request, $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(EnvironmentType::values())],
        ]);

        $slug = Str::slug($validated['name']);

        if ($model->environments()->where('slug', $slug)->exists()) {
            return response()->json([
                'message' => 'An environment with this name already exists.',
                'code' => 'environment_exists',
            ], 422);
        }

        $environment = Environment::query()->create([
            'organization_id' => $model->organization_id,
            'project_id' => $model->id,
            'name' => $validated['name'],
            'slug' => $slug,
            'type' => $validated['type'],
        ]);

        return response()->json(['data' => [
            'id' => $environment->id,
            'name' => $environment->name,
            'slug' => $environment->slug,
            'type' => $environment->type->value,
        ]], 201);
    }

    private function findProject(Request $request, string $projectId): Project
    {
        return $this->organization($request)->projects()->findOrFail($projectId);
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get(AuthenticateOrganizationToken::ORGANIZATION_ATTRIBUTE);
        abort_if($organization === null, 401);

        return $organization;
    }

    private function uniqueSlug(Organization $organization, string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while ($organization->projects()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
