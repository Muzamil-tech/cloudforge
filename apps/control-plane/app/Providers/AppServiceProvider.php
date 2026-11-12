<?php

namespace App\Providers;

use App\Domains\Infrastructure\Models\Server;
use App\Domains\Infrastructure\Services\AgentRequestVerifier;
use App\Domains\Infrastructure\Services\DockerSocketProbe;
use App\Domains\Infrastructure\Services\HostInspector;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Project;
use App\Policies\AgentCommandPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ServerPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostInspector::class, fn (): HostInspector => new HostInspector(
            dockerProbe: new DockerSocketProbe((string) config('cloudforge.agent.docker_socket', '/var/run/docker.sock')),
            osReleasePath: (string) config('cloudforge.bootstrap.os_release_path', '/etc/os-release'),
            machineIdPath: (string) config('cloudforge.bootstrap.machine_id_path', '/etc/machine-id'),
            dbusMachineIdPath: (string) config('cloudforge.bootstrap.dbus_machine_id_path', '/var/lib/dbus/machine-id'),
            memInfoPath: (string) config('cloudforge.bootstrap.meminfo_path', '/proc/meminfo'),
            cpuInfoPath: (string) config('cloudforge.bootstrap.cpuinfo_path', '/proc/cpuinfo'),
            machineIdOverride: config('cloudforge.bootstrap.machine_id') ?: null,
        ));
    }

    public function boot(): void
    {
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(AgentCommand::class, AgentCommandPolicy::class);

        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers());

        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->attributes->get('cloudforge.api_token');

            return Limit::perMinute(120)->by($token?->id ?? $request->ip());
        });

        RateLimiter::for('agent', function (Request $request): Limit {
            $fingerprint = (string) $request->header(AgentRequestVerifier::FINGERPRINT_HEADER);

            return Limit::perMinute(300)->by($fingerprint !== '' ? $fingerprint : $request->ip());
        });

        RateLimiter::for('agent-enroll', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('web', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->id ?? $request->ip()));
    }
}
