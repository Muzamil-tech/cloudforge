<?php

namespace App\Domains\Resources\Actions;

use App\Domains\Operations\Enums\AgentOperation;
use App\Domains\Operations\Enums\AuditAction;
use App\Domains\Operations\Models\AgentCommand;
use App\Domains\Operations\Services\AuditLogger;
use App\Domains\Resources\Enums\ResourceStatus;
use App\Domains\Resources\Models\Resource;

class HandleResourceCommandResult
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
        if (! $command->operation->isResourceOperation()) {
            return;
        }

        // The password is consumed by the resource record and must never remain
        // in the generic command history or API response, even if the resource
        // row or payload is malformed.
        if ($command->operation === AgentOperation::ResourceCreate && is_array($command->result)) {
            $redacted = $command->result;
            unset($redacted['password']);
            $command->forceFill(['result' => $redacted])->save();
        }

        $resourceId = $command->payload['resource_id'] ?? null;

        if (! is_string($resourceId)) {
            return;
        }

        $resource = Resource::withTrashed()->find($resourceId);

        if ($resource === null || $resource->server_id !== $command->server_id) {
            return;
        }

        if ($resource->last_command_id !== $command->command_id) {
            return;
        }

        match ($command->operation) {
            AgentOperation::ResourceCreate => $this->handleCreate($resource, $command, $completed, $result, $errorMessage),
            AgentOperation::ResourceStart => $this->handleStart($resource, $command, $completed, $errorMessage),
            AgentOperation::ResourceStop => $this->handleStop($resource, $command, $completed, $errorMessage),
            AgentOperation::ResourceDelete => $this->handleDelete($resource, $command, $completed, $errorMessage),
            AgentOperation::ResourceInspect => $this->handleInspect($resource, $command, $completed, $result, $errorMessage),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function handleCreate(
        Resource $resource,
        AgentCommand $command,
        bool $completed,
        array $result,
        ?string $errorMessage,
    ): void {
        if ($resource->status === ResourceStatus::Active && $resource->password !== null) {
            return;
        }

        if (! $completed) {
            $this->fail($resource, $command, $errorMessage ?? 'The agent could not provision the resource.');

            return;
        }

        $password = $result['password'] ?? null;
        $port = $result['port'] ?? null;
        $containerId = $result['container_id'] ?? null;
        $host = $result['host'] ?? null;
        $username = $result['username'] ?? null;
        $database = $result['database'] ?? null;

        if (! is_string($password) || $password === ''
            || ! is_numeric($port) || (int) $port < 1 || (int) $port > 65535
            || ! is_string($containerId) || $containerId === ''
            || ! is_string($host) || $host === '') {
            $this->fail($resource, $command, 'The agent returned incomplete resource credentials.');

            return;
        }

        $resource->forceFill([
            'status' => ResourceStatus::Active,
            'container_id' => $containerId,
            'host' => $host,
            'port' => (int) $port,
            'username' => is_string($username) && $username !== '' ? $username : null,
            'database_name' => is_string($database) && $database !== '' ? $database : null,
            'password' => $password,
            'credentials_revealed_at' => null,
            'last_error' => null,
        ])->save();

        $this->auditLogger->record(
            AuditAction::ResourceProvisioned,
            $resource->organization,
            $resource,
            [
                'type' => $resource->type->value,
                'server_id' => $resource->server_id,
            ],
        );
    }

    private function handleStart(
        Resource $resource,
        AgentCommand $command,
        bool $completed,
        ?string $errorMessage,
    ): void {
        if ($completed && $resource->status === ResourceStatus::Active) {
            return;
        }

        if (! $completed) {
            $this->fail($resource, $command, $errorMessage ?? 'The resource could not be started.');

            return;
        }

        $resource->forceFill(['status' => ResourceStatus::Active, 'last_error' => null])->save();

        $this->auditLogger->record(AuditAction::ResourceStarted, $resource->organization, $resource, [
            'type' => $resource->type->value,
        ]);
    }

    private function handleStop(
        Resource $resource,
        AgentCommand $command,
        bool $completed,
        ?string $errorMessage,
    ): void {
        if ($completed && $resource->status === ResourceStatus::Stopped) {
            return;
        }

        if (! $completed) {
            $this->fail($resource, $command, $errorMessage ?? 'The resource could not be stopped.');

            return;
        }

        $resource->forceFill(['status' => ResourceStatus::Stopped, 'last_error' => null])->save();

        $this->auditLogger->record(AuditAction::ResourceStopped, $resource->organization, $resource, [
            'type' => $resource->type->value,
        ]);
    }

    private function handleDelete(
        Resource $resource,
        AgentCommand $command,
        bool $completed,
        ?string $errorMessage,
    ): void {
        if ($completed && $resource->trashed()) {
            return;
        }

        if (! $completed) {
            $this->fail($resource, $command, $errorMessage ?? 'The resource could not be deleted.');

            return;
        }

        $resource->forceFill([
            'status' => ResourceStatus::Deleted,
            'password' => null,
            'username' => null,
            'database_name' => null,
            'last_error' => null,
        ])->save();

        $this->auditLogger->record(AuditAction::ResourceDeleted, $resource->organization, $resource, [
            'type' => $resource->type->value,
            'name' => $resource->name,
        ]);

        $resource->delete();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function handleInspect(
        Resource $resource,
        AgentCommand $command,
        bool $completed,
        array $result,
        ?string $errorMessage,
    ): void {
        if (! $completed) {
            $this->fail($resource, $command, $errorMessage ?? 'The resource state could not be read.');

            return;
        }

        $status = $result['status'] ?? null;
        $port = $result['port'] ?? null;
        $containerId = $result['container_id'] ?? null;

        if ($status === 'running') {
            $resource->status = ResourceStatus::Active;
        } elseif ($status === 'stopped') {
            $resource->status = ResourceStatus::Stopped;
        } elseif ($status === 'missing') {
            $resource->status = ResourceStatus::Failed;
            $resource->last_error = 'The managed container no longer exists on the server.';
        } else {
            $this->fail($resource, $command, 'The agent returned an unknown resource state.');

            return;
        }

        if (is_numeric($port) && (int) $port > 0 && (int) $port <= 65535) {
            $resource->port = (int) $port;
        }

        if (is_string($containerId) && $containerId !== '') {
            $resource->container_id = $containerId;
        }

        if ($resource->status !== ResourceStatus::Failed) {
            $resource->last_error = null;
        }

        $resource->save();
    }

    private function fail(Resource $resource, AgentCommand $command, string $message): void
    {
        $resource->forceFill([
            'status' => ResourceStatus::Failed,
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();

        $this->auditLogger->record(AuditAction::ResourceFailed, $resource->organization, $resource, [
            'type' => $resource->type->value,
            'operation' => $command->operation->value,
        ]);
    }
}
