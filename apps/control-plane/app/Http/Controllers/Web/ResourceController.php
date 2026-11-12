<?php

namespace App\Http\Controllers\Web;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Projects\Models\Environment;
use App\Domains\Projects\Models\Project;
use App\Domains\Resources\Actions\CreateResource;
use App\Domains\Resources\Actions\DeleteResource;
use App\Domains\Resources\Actions\RefreshResource;
use App\Domains\Resources\Actions\StartResource;
use App\Domains\Resources\Actions\StopResource;
use App\Domains\Resources\Enums\ResourceType;
use App\Domains\Resources\Models\Resource;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ResourceController extends Controller
{
    public function store(
        Request $request,
        Project $project,
        OrganizationContext $organizationContext,
        CreateResource $createResource,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('view', $project);
        Gate::authorize('create', [Resource::class, $project]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', 'string', Rule::in(ResourceType::values())],
            'environment_id' => ['required', 'uuid'],
            'server_id' => ['required', 'uuid'],
        ]);

        $environment = Environment::query()
            ->where('organization_id', $organization->id)
            ->where('project_id', $project->id)
            ->findOrFail($validated['environment_id']);

        $server = Server::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($validated['server_id']);

        $resource = $createResource->execute(
            $project,
            $environment,
            $server,
            trim($validated['name']),
            ResourceType::from($validated['type']),
            $request->user(),
        );

        return back()->with(
            'success',
            "{$resource->name} is being provisioned. Connection credentials will appear when the agent finishes.",
        );
    }

    public function start(
        Request $request,
        Project $project,
        Resource $resource,
        OrganizationContext $organizationContext,
        StartResource $startResource,
    ): RedirectResponse {
        $this->authorizeResource($request, $project, $resource, $organizationContext);
        Gate::authorize('start', $resource);

        $startResource->execute($resource, $request->user());

        return back()->with('success', "{$resource->name} is starting.");
    }

    public function stop(
        Request $request,
        Project $project,
        Resource $resource,
        OrganizationContext $organizationContext,
        StopResource $stopResource,
    ): RedirectResponse {
        $this->authorizeResource($request, $project, $resource, $organizationContext);
        Gate::authorize('stop', $resource);

        $stopResource->execute($resource, $request->user());

        return back()->with('success', "{$resource->name} is stopping.");
    }

    public function refresh(
        Request $request,
        Project $project,
        Resource $resource,
        OrganizationContext $organizationContext,
        RefreshResource $refreshResource,
    ): RedirectResponse {
        $this->authorizeResource($request, $project, $resource, $organizationContext);
        Gate::authorize('refresh', $resource);

        $refreshResource->execute($resource, $request->user());

        return back()->with('success', 'Resource state refresh requested.');
    }

    public function reveal(
        Request $request,
        Project $project,
        Resource $resource,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->authorizeResource($request, $project, $resource, $organizationContext);
        Gate::authorize('reveal', $resource);

        if (! $resource->canRevealCredentials()) {
            return back()->with('error', 'These credentials have already been revealed or are not ready yet.');
        }

        $password = $resource->password;
        $username = $resource->username;
        $database = $resource->database_name;

        $resource->forceFill(['credentials_revealed_at' => now()])->save();

        $auditLogger->record(
            AuditAction::ResourceCredentialsRevealed,
            $resource->organization,
            $resource,
            ['type' => $resource->type->value],
            $request->user(),
        );

        $credentials = [
            'name' => $resource->name,
            'type' => $resource->type->label(),
            'host' => $resource->host,
            'port' => $resource->port,
            'username' => $username,
            'database' => $database,
            'password' => $password,
            'url' => $this->connectionUrl($resource, $password, $username, $database),
        ];

        return back()->with('resource_credentials', $credentials);
    }

    public function destroy(
        Request $request,
        Project $project,
        Resource $resource,
        OrganizationContext $organizationContext,
        DeleteResource $deleteResource,
    ): RedirectResponse {
        $this->authorizeResource($request, $project, $resource, $organizationContext);
        Gate::authorize('delete', $resource);

        $deleteResource->execute($resource, $request->user());

        return back()->with('success', "{$resource->name} is being deleted, including its persistent volume.");
    }

    private function authorizeResource(
        Request $request,
        Project $project,
        Resource $resource,
        OrganizationContext $organizationContext,
    ): void {
        $organization = $organizationContext->require($request);

        abort_unless(
            $resource->organization_id === $organization->id && $resource->project_id === $project->id,
            404,
        );

        Gate::authorize('view', $project);
    }

    private function connectionUrl(Resource $resource, ?string $password, ?string $username, ?string $database): string
    {
        if ($resource->type === ResourceType::Postgresql) {
            return sprintf(
                'postgresql://%s:%s@%s:%d/%s',
                rawurlencode((string) $username),
                rawurlencode((string) $password),
                $resource->host,
                $resource->port,
                rawurlencode((string) $database),
            );
        }

        return sprintf(
            'redis://:%s@%s:%d/0',
            rawurlencode((string) $password),
            $resource->host,
            $resource->port,
        );
    }
}
