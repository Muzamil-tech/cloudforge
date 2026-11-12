<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class CommandResultRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:completed,failed'],
            'result' => ['nullable', 'array'],
            'error_message' => ['nullable', 'string', 'max:2000'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
