<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Web\ApiTokenController;
use App\Http\Controllers\Web\ApplicationController;
use App\Http\Controllers\Web\AuditLogController;
use App\Http\Controllers\Web\MemberController;
use App\Http\Controllers\Web\OrganizationController;
use App\Http\Controllers\Web\ProjectController;
use App\Http\Controllers\Web\ResourceController;
use App\Http\Controllers\Web\ServerController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');

    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

    Route::middleware(['org.required', 'throttle:web'])->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::post('organizations/switch/{organization}', [OrganizationController::class, 'switch'])->name('organizations.switch');
        Route::get('settings', [OrganizationController::class, 'settings'])->name('organization.settings');
        Route::patch('settings', [OrganizationController::class, 'update'])->name('organization.update');

        Route::resource('servers', ServerController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
        Route::post('servers/{server}/enrollment', [ServerController::class, 'issueEnrollment'])->name('servers.enrollment');
        Route::post('servers/{server}/certificates/revoke', [ServerController::class, 'revokeCertificates'])->name('servers.certificates.revoke');
        Route::post('servers/{server}/state', [ServerController::class, 'changeState'])->name('servers.state');
        Route::post('servers/{server}/commands', [ServerController::class, 'issueCommand'])->name('servers.commands.store');
        Route::post('servers/{server}/commands/{command}/cancel', [ServerController::class, 'cancelCommand'])->name('servers.commands.cancel');

        Route::get('databases', [ResourceController::class, 'index'])->name('databases.index');

        Route::resource('projects', ProjectController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('projects/{project}/databases', [ProjectController::class, 'databases'])->name('projects.databases');
        Route::get('projects/{project}/environments', [ProjectController::class, 'environmentsTab'])->name('projects.environments');
        Route::get('projects/{project}/activity', [ProjectController::class, 'activity'])->name('projects.activity');
        Route::get('projects/{project}/settings', [ProjectController::class, 'settings'])->name('projects.settings');
        Route::post('projects/{project}/environments', [ProjectController::class, 'storeEnvironment'])->name('projects.environments.store');
        Route::delete('projects/{project}/environments/{environment}', [ProjectController::class, 'destroyEnvironment'])->name('projects.environments.destroy');

        Route::post('projects/{project}/resources', [ResourceController::class, 'store'])->name('resources.store');
        Route::post('projects/{project}/resources/{resource}/start', [ResourceController::class, 'start'])->name('resources.start');
        Route::post('projects/{project}/resources/{resource}/stop', [ResourceController::class, 'stop'])->name('resources.stop');
        Route::post('projects/{project}/resources/{resource}/refresh', [ResourceController::class, 'refresh'])->name('resources.refresh');
        Route::post('projects/{project}/resources/{resource}/recover', [ResourceController::class, 'recover'])->name('resources.recover');
        Route::post('projects/{project}/resources/{resource}/reveal', [ResourceController::class, 'reveal'])->name('resources.reveal');
        Route::delete('projects/{project}/resources/{resource}', [ResourceController::class, 'destroy'])->name('resources.destroy');

        Route::get('projects/{project}/applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::post('projects/{project}/applications', [ApplicationController::class, 'store'])->name('applications.store');
        Route::get('projects/{project}/applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
        Route::post('projects/{project}/applications/{application}/deploy', [ApplicationController::class, 'deploy'])->name('applications.deploy');
        Route::post('projects/{project}/applications/{application}/rollback/{deployment}', [ApplicationController::class, 'rollback'])->name('applications.rollback');
        Route::post('projects/{project}/applications/{application}/start', [ApplicationController::class, 'start'])->name('applications.start');
        Route::post('projects/{project}/applications/{application}/stop', [ApplicationController::class, 'stop'])->name('applications.stop');
        Route::post('projects/{project}/applications/{application}/refresh', [ApplicationController::class, 'refresh'])->name('applications.refresh');
        Route::delete('projects/{project}/applications/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy');

        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::post('members/invitations', [MemberController::class, 'invite'])->name('members.invitations.store');
        Route::delete('members/invitations/{invitation}', [MemberController::class, 'revokeInvitation'])->name('members.invitations.destroy');
        Route::patch('members/{membership}', [MemberController::class, 'updateRole'])->name('members.update');
        Route::delete('members/{membership}', [MemberController::class, 'remove'])->name('members.destroy');

        Route::get('api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
        Route::post('api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
    });
});
