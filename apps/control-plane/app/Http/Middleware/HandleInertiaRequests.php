<?php

namespace App\Http\Middleware;

use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     */
    protected $rootView = 'app';

    public function __construct(private readonly OrganizationContext $organizationContext) {}

    /**
     * Define the props shared with every Inertia response.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $organization = $this->organizationContext->current($request);

        return [
            ...parent::share($request),
            'appName' => config('cloudforge.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
            'currentOrganization' => $organization === null ? null : [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $this->organizationContext->roleFor($request, $organization),
            ],
            'organizations' => $user === null ? [] : $user->organizations()
                ->orderBy('name')
                ->get()
                ->map(fn ($organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'role' => $organization->pivot->role,
                ])
                ->all(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'install_command' => fn () => $request->session()->get('install_command'),
                'enrollment_token' => fn () => $request->session()->get('enrollment_token'),
                'enrollment_expires_at' => fn () => $request->session()->get('enrollment_expires_at'),
                'plaintext_token' => fn () => $request->session()->get('plaintext_token'),
                'invitation_url' => fn () => $request->session()->get('invitation_url'),
            ],
            'features' => [
                'registrationEnabled' => (bool) config('cloudforge.registration.enabled'),
            ],
        ];
    }
}
