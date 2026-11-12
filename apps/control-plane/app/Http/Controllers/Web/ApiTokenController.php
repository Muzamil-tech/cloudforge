<?php

namespace App\Http\Controllers\Web;

use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Organizations\Actions\CreateApiToken;
use App\Domains\Organizations\Models\OrganizationApiToken;
use App\Http\Controllers\Controller;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    private const ABILITIES = [
        'servers:read' => 'Read servers, health and certificates',
        'servers:write' => 'Create, update and delete servers',
        'commands:write' => 'Issue and cancel agent commands',
        'projects:read' => 'Read projects and environments',
        'projects:write' => 'Create, update and delete projects',
        'audit:read' => 'Read the organization audit log',
    ];

    public function index(Request $request, OrganizationContext $organizationContext): Response
    {
        $organization = $organizationContext->require($request);
        Gate::authorize('view', $organization);

        return Inertia::render('ApiTokens/Index', [
            'tokens' => $organization->apiTokens()
                ->with('createdBy')
                ->latest()
                ->get()
                ->map(fn (OrganizationApiToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'created_by' => $token->createdBy?->name ?? 'System',
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'expired' => $token->isExpired(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ])
                ->all(),
            'abilities' => collect(self::ABILITIES)
                ->map(fn (string $description, string $value): array => [
                    'value' => $value,
                    'description' => $description,
                ])
                ->values()
                ->all(),
            'canManage' => Gate::allows('manageApiTokens', $organization),
        ]);
    }

    public function store(
        Request $request,
        OrganizationContext $organizationContext,
        CreateApiToken $createApiToken,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('manageApiTokens', $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(array_keys(self::ABILITIES))],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $result = $createApiToken->execute(
            $organization,
            $request->user(),
            $validated['name'],
            $validated['abilities'],
            isset($validated['expires_in_days'])
                ? now()->addDays((int) $validated['expires_in_days'])
                : null,
        );

        return back()
            ->with('success', 'API token created. Copy it now — it will not be shown again.')
            ->with('plaintext_token', $result['plaintext']);
    }

    public function destroy(
        Request $request,
        OrganizationApiToken $apiToken,
        OrganizationContext $organizationContext,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $organization = $organizationContext->require($request);
        Gate::authorize('manageApiTokens', $organization);

        abort_unless($apiToken->organization_id === $organization->id, 404);

        $auditLogger->record(
            AuditAction::ApiTokenRevoked,
            $organization,
            $apiToken,
            ['name' => $apiToken->name],
            $request->user(),
        );

        $apiToken->delete();

        return back()->with('success', "API token {$apiToken->name} was revoked.");
    }
}
