<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateOrganizationToken;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get(AuthenticateOrganizationToken::ORGANIZATION_ATTRIBUTE);

        $logs = $organization->auditLogs()
            ->with('actor')
            ->when($request->string('action')->toString(), fn ($query, string $action) => $query->where('action', $action))
            ->latest('created_at')
            ->paginate(100);

        return AuditLogResource::collection($logs);
    }
}
