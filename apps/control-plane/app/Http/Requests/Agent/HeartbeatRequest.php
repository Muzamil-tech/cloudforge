<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
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
            'agent_version' => ['required', 'string', 'max:64'],
            'docker_available' => ['required', 'boolean'],
            'docker_version' => ['nullable', 'string', 'max:128'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'load_average' => ['nullable', 'array', 'max:3'],
            'load_average.*' => ['numeric', 'min:0'],
        ];
    }
}
