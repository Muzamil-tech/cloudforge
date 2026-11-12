<?php

namespace App\Support;

use App\Domains\Organizations\Enums\OrganizationRole;
use App\Domains\Organizations\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Http\Middleware\AuthenticateOrganizationToken;
use Illuminate\Http\Request;

class OrganizationContext
{
    private const ATTRIBUTE = 'cloudforge.organization';

    public function current(Request $request): ?Organization
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        if ($request->attributes->has(AuthenticateOrganizationToken::ORGANIZATION_ATTRIBUTE)) {
            $organization = $request->attributes->get(AuthenticateOrganizationToken::ORGANIZATION_ATTRIBUTE);
            $request->attributes->set(self::ATTRIBUTE, $organization);

            return $organization;
        }

        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $organizationId = $request->hasSession()
            ? $request->session()->get('current_organization_id')
            : null;

        $membership = $organizationId === null
            ? null
            : $user->memberships()
                ->with('organization')
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->first();

        $membership ??= $user->memberships()
            ->with('organization')
            ->where('status', 'active')
            ->orderBy('joined_at')
            ->first();

        if ($membership === null) {
            return null;
        }

        if ($request->hasSession() && $organizationId !== $membership->organization_id) {
            $request->session()->put('current_organization_id', $membership->organization_id);
        }

        $request->attributes->set(self::ATTRIBUTE, $membership->organization);

        return $membership->organization;
    }

    public function set(Request $request, Organization $organization): void
    {
        $request->attributes->set(self::ATTRIBUTE, $organization);

        if ($request->hasSession()) {
            $request->session()->put('current_organization_id', $organization->id);
        }
    }

    public function clear(Request $request): void
    {
        $request->attributes->forget(self::ATTRIBUTE);

        if ($request->hasSession()) {
            $request->session()->forget('current_organization_id');
        }
    }

    public function membership(Request $request, ?Organization $organization = null): ?Membership
    {
        $organization ??= $this->current($request);
        $user = $request->user();

        if ($organization === null || $user === null) {
            return null;
        }

        return $organization->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    public function roleFor(Request $request, ?Organization $organization = null): ?string
    {
        return $this->membership($request, $organization)?->role->value;
    }

    public function role(Request $request, ?Organization $organization = null): ?OrganizationRole
    {
        return $this->membership($request, $organization)?->role;
    }

    public function require(Request $request): Organization
    {
        $organization = $this->current($request);

        abort_if($organization === null, 403, 'You do not have an active organization.');

        return $organization;
    }
}
