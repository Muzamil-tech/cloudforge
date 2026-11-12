<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class MetricsRequest extends FormRequest
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
        $max = (int) config('cloudforge.metrics.max_points_per_request', 240);

        return [
            'points' => ['required', 'array', 'min:1', "max:{$max}"],
            'points.*.recorded_at' => ['required', 'date'],
            'points.*.cpu_usage' => ['nullable', 'numeric', 'between:0,100'],
            'points.*.memory_usage' => ['nullable', 'numeric', 'between:0,100'],
            'points.*.disk_usage' => ['nullable', 'numeric', 'between:0,100'],
            'points.*.memory_used_bytes' => ['nullable', 'integer', 'min:0'],
            'points.*.disk_used_bytes' => ['nullable', 'integer', 'min:0'],
            'points.*.network_in_bytes' => ['nullable', 'integer', 'min:0'],
            'points.*.network_out_bytes' => ['nullable', 'integer', 'min:0'],
            'points.*.metadata' => ['nullable', 'array'],
        ];
    }
}
