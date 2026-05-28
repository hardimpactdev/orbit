<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use Orbit\Core\Progress\ProgressEventType;

final class DoctorTerminalFrameExtractor
{
    /**
     * @param  array{type: ProgressEventType, payload: array<string, mixed>}  $frame
     * @return array<string, mixed>|null
     */
    public function doctor(array $frame): ?array
    {
        $payload = $frame['payload'];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $doctor = $data['doctor'] ?? null;

        if (is_array($doctor)) {
            return $doctor;
        }

        $nestedData = is_array($data['data'] ?? null) ? $data['data'] : [];
        $nestedDoctor = $nestedData['doctor'] ?? null;

        return is_array($nestedDoctor) ? $nestedDoctor : null;
    }
}
