<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->attributes->get(AuthenticateOrganizationToken::TOKEN_ATTRIBUTE);

        if ($token === null || ! $token->can($ability)) {
            return response()->json([
                'message' => 'This API token does not have the required ability.',
                'code' => 'forbidden',
                'required_ability' => $ability,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
