<?php

namespace App\Domains\Applications\Actions;

use App\Domains\Applications\Enums\ApplicationStatus;
use App\Domains\Applications\Enums\DeploymentStatus;
use App\Domains\Applications\Models\Application;
use App\Domains\Applications\Models\Deployment;
use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Operations\Services\AuditLogger;

class HandleApplicationCommandResult
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public function handle(
        AgentCommand $command,
        bool $completed,
        array $result,
        ?string $errorMessage,
    ): void {
        if (! $command->operation->isApplicationOperation()) {
            return;
        }

        $applicationId = $command->payload['application_id'] ?? null;

        if (! is_string($applicationId)) {
            return;
        }

        $application = Application::withTrashed()->find($applicationId);

        if ($application === null || $application->server_id !== $command->server_id) {
            return;
        }

        if ($application->last_command_id !== $command->command_id) {
            return;
        }

        match ($command->operation) {
            AgentOperation::ApplicationDeploy => $this->handleDeploy($application, $command, $completed, $result, $errorMessage),
            AgentOperation::ApplicationStart => $this->handleStart($application, $completed, $errorMessage),
            AgentOperation::ApplicationStop => $this->handleStop($application, $completed, $errorMessage),
            AgentOperation::ApplicationDelete => $this->handleDelete($application, $completed, $errorMessage),
            AgentOperation::ApplicationInspect => $this->handleInspect($application, $completed, $result, $errorMessage),
            AgentOperation::ApplicationLogs => $this->handleLogs($application, $completed, $result),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function handleDeploy(
        Application $application,
        AgentCommand $command,
        bool $completed,
        array $result,
        ?string $errorMessage,
    ): void {
        $deployment = $this->deploymentFor($application, $command);

        if ($deployment === null) {
            $this->failApplication($application, 'The deployment record could not be found.', $command);

            return;
        }

        $logs = is_string($result['logs'] ?? null) ? $result['logs'] : null;
        $warnings = $this->warnings($result['warnings'] ?? []);

        if (! $completed) {
            $this->failDeployment(
                $application,
                $deployment,
                $errorMessage ?? 'The agent could not deploy the application.',
                $logs,
                $warnings,
            );

            return;
        }

        $containerId = $result['container_id'] ?? null;
        $containerName = $result['container_name'] ?? null;
        $host = $result['host'] ?? null;
        $port = $result['port'] ?? null;
        $status = $result['status'] ?? null;

        if (! is_string($containerId) || $containerId === ''
            || ! is_string($containerName) || $containerName === ''
            || ! is_string($host) || $host === ''
            || ! is_numeric($port) || (int) $port < 1 || (int) $port > 65535
            || $status !== 'running') {
            $this->failDeployment(
                $application,
                $deployment,
                'The agent returned incomplete application connection details.',
                $logs,
                $warnings,
            );

            return;
        }

        $application->forceFill([
            'status' => ApplicationStatus::Active,
            'image' => $deployment->image,
            'container_name' => $containerName,
            'container_id' => $containerId,
            'host' => $host,
            'host_port' => (int) $port,
            'last_error' => null,
        ])->save();

        $deployment->forceFill([
            'status' => DeploymentStatus::Active,
            'container_id' => $containerId,
            'host_port' => (int) $port,
            'logs' => $this->boundedLogs($logs),
            'error_message' => null,
            'finished_at' => now(),
        ])->save();

        Deployment::query()
            ->where('application_id', $application->id)
            ->where('id', '!=', $deployment->id)
            ->where('status', DeploymentStatus::Active->value)
            ->update(['status' => DeploymentStatus::Superseded->value, 'updated_at' => now()]);

        $this->event($deployment, 'info', 'Deployment completed and the application is healthy.', [
            'container_id' => $containerId,
            'port' => (int) $port,
            'health' => is_string($result['health'] ?? null) ? $result['health'] : null,
        ]);

        foreach ($warnings as $warning) {
            $this->event($deployment, 'warning', $warning);
        }

        $this->auditLogger->record(
            AuditAction::ApplicationDeployed,
            $application->organization,
            $application,
            [
                'deployment_id' => $deployment->id,
                'image' => $deployment->image,
                'container_id' => $containerId,
            ],
        );
    }

    private function handleStart(Application $application, bool $completed, ?string $errorMessage): void
    {
        if (! $completed) {
            $this->failApplication($application, $errorMessage ?? 'The application could not be started.');

            return;
        }

        $application->forceFill(['status' => ApplicationStatus::Active, 'last_error' => null])->save();

        $this->auditLogger->record(AuditAction::ApplicationStarted, $application->organization, $application, [
            'container_id' => $application->container_id,
        ]);
    }

    private function handleStop(Application $application, bool $completed, ?string $errorMessage): void
    {
        if (! $completed) {
            $application->forceFill([
                'status' => $application->container_id !== null ? ApplicationStatus::Active : ApplicationStatus::Failed,
                'last_error' => mb_substr($errorMessage ?? 'The application could not be stopped.', 0, 2000),
            ])->save();

            return;
        }

        $application->forceFill(['status' => ApplicationStatus::Stopped, 'last_error' => null])->save();

        $this->auditLogger->record(AuditAction::ApplicationStopped, $application->organization, $application, [
            'container_id' => $application->container_id,
        ]);
    }

    private function handleDelete(Application $application, bool $completed, ?string $errorMessage): void
    {
        if (! $completed) {
            $this->failApplication($application, $errorMessage ?? 'The application could not be deleted.');

            return;
        }

        Deployment::query()
            ->where('application_id', $application->id)
            ->where('status', DeploymentStatus::Deploying->value)
            ->update([
                'status' => DeploymentStatus::Cancelled->value,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $application->forceFill([
            'status' => ApplicationStatus::Deleted,
            'last_error' => null,
        ])->save();

        $this->auditLogger->record(AuditAction::ApplicationDeleted, $application->organization, $application, [
            'name' => $application->name,
        ]);

        $application->delete();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function handleInspect(
        Application $application,
        bool $completed,
        array $result,
        ?string $errorMessage,
    ): void {
        if (! $completed) {
            $this->failApplication($application, $errorMessage ?? 'The application state could not be read.');

            return;
        }

        $status = $result['status'] ?? null;
        $port = $result['port'] ?? null;
        $containerId = $result['container_id'] ?? null;
        $image = $result['image'] ?? null;

        if ($status === 'running') {
            $application->status = ApplicationStatus::Active;
        } elseif ($status === 'stopped') {
            $application->status = ApplicationStatus::Stopped;
        } elseif ($status === 'missing') {
            $application->status = ApplicationStatus::Failed;
            $application->last_error = 'The application container no longer exists on the server.';
        } else {
            $this->failApplication($application, 'The agent returned an unknown application state.');

            return;
        }

        if (is_numeric($port) && (int) $port > 0 && (int) $port <= 65535) {
            $application->host_port = (int) $port;
        }

        if (is_string($containerId) && $containerId !== '') {
            $application->container_id = $containerId;
        }

        if (is_string($image) && $image !== '') {
            $application->image = $image;
        }

        if ($application->status !== ApplicationStatus::Failed) {
            $application->last_error = null;
        }

        $application->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function handleLogs(Application $application, bool $completed, array $result): void
    {
        if (! $completed) {
            return;
        }

        $logs = $result['logs'] ?? null;

        if (! is_string($logs)) {
            return;
        }

        $deployment = $application->latestDeployment;

        if ($deployment === null) {
            return;
        }

        $deployment->forceFill(['logs' => $this->boundedLogs($logs)])->save();
    }

    /**
     * @param  list<string>  $warnings
     */
    private function failDeployment(
        Application $application,
        Deployment $deployment,
        string $message,
        ?string $logs,
        array $warnings,
    ): void {
        $deployment->forceFill([
            'status' => DeploymentStatus::Failed,
            'logs' => $this->boundedLogs($logs),
            'error_message' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
        ])->save();

        $previousContainerName = $deployment->metadata['previous_container_name'] ?? null;

        if (is_string($previousContainerName) && $previousContainerName !== '') {
            $previousStatus = $deployment->metadata['previous_status'] ?? null;

            $application->forceFill([
                'status' => $previousStatus === ApplicationStatus::Stopped->value
                    ? ApplicationStatus::Stopped
                    : ApplicationStatus::Active,
                'last_error' => mb_substr($message, 0, 2000),
            ])->save();
        } else {
            $application->forceFill([
                'status' => ApplicationStatus::Failed,
                'last_error' => mb_substr($message, 0, 2000),
            ])->save();
        }

        $this->event($deployment, 'error', $message);

        foreach ($warnings as $warning) {
            $this->event($deployment, 'warning', $warning);
        }

        $this->auditLogger->record(
            AuditAction::DeploymentFailed,
            $application->organization,
            $application,
            [
                'deployment_id' => $deployment->id,
                'image' => $deployment->image,
            ],
        );
    }

    private function failApplication(Application $application, string $message, ?AgentCommand $command = null): void
    {
        $application->forceFill([
            'status' => ApplicationStatus::Failed,
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();

        $this->auditLogger->record(
            AuditAction::ApplicationFailed,
            $application->organization,
            $application,
            [
                'operation' => $command?->operation->value,
                'command_id' => $command?->command_id,
            ],
        );
    }

    private function deploymentFor(Application $application, AgentCommand $command): ?Deployment
    {
        $deploymentId = $command->payload['deployment_id'] ?? null;

        if (! is_string($deploymentId)) {
            return null;
        }

        return Deployment::query()
            ->where('id', $deploymentId)
            ->where('application_id', $application->id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function event(Deployment $deployment, string $level, string $message, array $context = []): void
    {
        $deployment->events()->create([
            'level' => $level,
            'message' => mb_substr($message, 0, 2000),
            'context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * @return list<string>
     */
    private function warnings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $warning): string => is_string($warning) ? trim($warning) : '', $value),
            fn (string $warning): bool => $warning !== '',
        ));
    }

    private function boundedLogs(?string $logs): ?string
    {
        if ($logs === null) {
            return null;
        }

        return mb_strcut($logs, 0, 65535, 'UTF-8');
    }
}
