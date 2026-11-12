<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateOrganizationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $request->attributes->get(AuthenticateOrganizationToken::ORGANIZATION_ATTRIBUTE);
        $token = $request->attributes->get(AuthenticateOrganizationToken::TOKEN_ATTRIBUTE);

        abort_if($organization === null || $token === null, 401);

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'token' => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
        ]);
    }
}
