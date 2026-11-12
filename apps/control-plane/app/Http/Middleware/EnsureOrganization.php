<?php

namespace App\Http\Middleware;

use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganization
{
    public function __construct(private readonly OrganizationContext $organizationContext) {}

    /**
     * Send users without an active organization to the onboarding flow.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->organizationContext->current($request) === null) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
