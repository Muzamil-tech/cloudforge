<?php

namespace App\Http\Controllers\Web;

use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Actions\CreateOrganization;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationApiToken;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function store(
        Request $request,
        CreateOrganization $createOrganization,
        OrganizationContext $organizationContext,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $organization = $createOrganization->execute($request->user(), ['name' => $validated['name']]);
        $organizationContext->set($request, $organization);

        return redirect()->route('dashboard')
            ->with('success', "{$organization->name} was created.");
    }

    public function update(
        Request $request,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $organization->fill($validated)->save();

        $auditLogger->record(
            AuditAction::OrganizationUpdated,
            $organization,
            $organization,
            ['name' => $organization->name],
            $request->user(),
        );

        return back()->with('success', 'Organization settings saved.');
    }

    public function switch(
        Request $request,
        Organization $organization,
        OrganizationContext $organizationContext,
    ): RedirectResponse {
        Gate::authorize('view', $organization);

        $organizationContext->set($request, $organization);

        return redirect()->route('dashboard');
    }

    public function settings(Request $request, OrganizationContext $organizationContext): Response
    {
        $organization = $organizationContext->require($request);
        Gate::authorize('update', $organization);

        return Inertia::render('Organization/Settings', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'plan' => $organization->plan,
                'created_at' => $organization->created_at?->toIso8601String(),
                'members_count' => $organization->memberships()->where('status', 'active')->count(),
                'api_tokens_count' => $organization->apiTokens()->count(),
            ],
            'apiTokens' => $organization->apiTokens()
                ->latest()
                ->get()
                ->map(fn (OrganizationApiToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                ])
                ->all(),
        ]);
    }
}
