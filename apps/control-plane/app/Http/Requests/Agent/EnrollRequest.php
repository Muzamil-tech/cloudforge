<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class EnrollRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:255'],
            'csr' => ['required', 'string', 'max:16384'],
            'protocol_version' => ['nullable', 'string', 'max:16'],
            'agent_version' => ['required', 'string', 'max:64'],
            'hostname' => ['required', 'string', 'max:255'],
            'operating_system' => ['nullable', 'string', 'max:255'],
            'architecture' => ['nullable', 'string', 'max:64'],
            'docker_version' => ['nullable', 'string', 'max:128'],
            'machine_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
