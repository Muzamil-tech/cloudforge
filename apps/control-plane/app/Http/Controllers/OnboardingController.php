<?php

namespace App\Http\Controllers;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Organizations\Actions\CreateOrganization;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request, OrganizationContext $organizationContext): Response|RedirectResponse
    {
        if ($organizationContext->current($request) !== null) {
            return redirect()->route('dashboard');
        }

        $bootstrapNodes = Server::query()
            ->whereNull('organization_id')
            ->where('is_bootstrap', true)
            ->with(['enrollments' => fn ($query) => $query
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->latest('created_at')])
            ->orderBy('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Server $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'machine_id' => $server->machine_id,
                'status' => $server->status->value,
                'status_label' => $server->status->label(),
                'tone' => $server->status->tone(),
                'agent_enrolled' => $server->activeCertificate() !== null,
                'enrollment_expires_at' => $server->enrollments->first()?->expires_at?->toIso8601String(),
                'last_heartbeat_at' => $server->last_heartbeat_at?->toIso8601String(),
                'created_at' => $server->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Onboarding', [
            'bootstrapNodes' => $bootstrapNodes,
        ]);
    }

    public function store(
        Request $request,
        CreateOrganization $createOrganization,
        OrganizationContext $organizationContext,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $organization = $createOrganization->execute($request->user(), [
            'name' => $validated['name'],
        ]);

        $organizationContext->set($request, $organization);

        return redirect()->route('dashboard')
            ->with('success', "{$organization->name} is ready.");
    }
}
