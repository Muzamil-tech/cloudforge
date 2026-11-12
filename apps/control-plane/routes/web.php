<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Web\ApiTokenController;
use App\Http\Controllers\Web\AuditLogController;
use App\Http\Controllers\Web\MemberController;
use App\Http\Controllers\Web\OrganizationController;
use App\Http\Controllers\Web\ProjectController;
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

        Route::resource('projects', ProjectController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::post('projects/{project}/environments', [ProjectController::class, 'storeEnvironment'])->name('projects.environments.store');
        Route::delete('projects/{project}/environments/{environment}', [ProjectController::class, 'destroyEnvironment'])->name('projects.environments.destroy');

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
