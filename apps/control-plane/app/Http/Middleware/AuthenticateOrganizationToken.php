<?php

namespace App\Http\Middleware;

use App\Domains\Organizations\Models\OrganizationApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOrganizationToken
{
    public const ORGANIZATION_ATTRIBUTE = 'cloudforge.api_organization';

    public const TOKEN_ATTRIBUTE = 'cloudforge.api_token';

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || ! str_starts_with($bearer, 'cf_')) {
            return $this->unauthorized('A CloudForge organization API token is required.');
        }

        $token = OrganizationApiToken::query()
            ->with('organization')
            ->where('token_hash', OrganizationApiToken::hashToken($bearer))
            ->first();

        if ($token === null || $token->organization === null || $token->isExpired()) {
            return $this->unauthorized('The API token is invalid or expired.');
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set(self::ORGANIZATION_ATTRIBUTE, $token->organization);
        $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);

        if ($token->created_by_user_id !== null) {
            $request->setUserResolver(fn () => $token->createdBy);
        }

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'message' => $message,
            'code' => 'unauthenticated',
        ], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
