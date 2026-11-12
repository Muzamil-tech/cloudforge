<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:255'],
            'operating_system' => ['nullable', 'string', 'max:255'],
            'architecture' => ['nullable', 'string', 'max:64'],
            'cpu_cores' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'total_memory_bytes' => ['nullable', 'integer', 'min:0'],
            'total_disk_bytes' => ['nullable', 'integer', 'min:0'],
            'docker_version' => ['nullable', 'string', 'max:128'],
            'agent_version' => ['required', 'string', 'max:64'],
            'collected_at' => ['nullable', 'date'],
            'docker' => ['nullable', 'array'],
            'docker.available' => ['nullable', 'boolean'],
            'docker.version' => ['nullable', 'string', 'max:128'],
            'docker.storage_driver' => ['nullable', 'string', 'max:64'],
            'docker.rootless' => ['nullable', 'boolean'],
            'capabilities' => ['nullable', 'array', 'max:64'],
            'capabilities.*.name' => ['required', 'string', 'max:64', 'distinct'],
            'capabilities.*.version' => ['nullable', 'string', 'max:128'],
            'capabilities.*.metadata' => ['nullable', 'array'],
        ];
    }
}
