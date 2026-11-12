<?php

namespace App\Domains\Applications\Support;

use Closure;

final class ApplicationImage
{
    private const PATTERN = '/^(?:(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)(?::[0-9]{1,5})?\/)?(?:[a-z0-9]+(?:[._-][a-z0-9]+)*\/)*[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(?:@sha256:[a-f0-9]{64})?$/';

    public static function isValid(string $image): bool
    {
        $image = trim($image);

        return $image !== ''
            && strlen($image) <= 255
            && preg_match(self::PATTERN, $image) === 1;
    }

    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isValid($value)) {
                $fail('The image must be a valid container image reference, for example nginx:1.27-alpine.');
            }
        };
    }

    public static function containerName(string $applicationId, string $deploymentId): string
    {
        return 'cloudforge-app-'.$applicationId.'-'.substr(str_replace('-', '', $deploymentId), 0, 8);
    }
}
