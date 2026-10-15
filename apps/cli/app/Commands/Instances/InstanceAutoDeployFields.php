<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use Orbit\Sdk\Responses\Instances\InstanceResponse;

/** The detail fields that describe an Instance's automatic deployment for humans. */
final class InstanceAutoDeployFields
{
    /** @return array<string, string|null> */
    public static function of(InstanceResponse $instance): array
    {
        $auto = $instance->autoDeploy;

        if ($auto === null || ! $auto->enabled) {
            return ['Auto deploy' => 'off'];
        }

        $fields = ['Auto deploy' => $auto->gate === null ? 'on, without a merge check' : "on, after {$auto->gate} passes"];

        if ($auto->result !== null) {
            $fields['Auto deploy state'] = $auto->result.($auto->commit !== null ? ' '.substr($auto->commit, 0, 12) : '');
        }

        if ($auto->pause !== null) {
            $fields['Auto deploy paused'] = "{$auto->pause['reason']} (deployment {$auto->pause['deployment_id']}); run instance:update {$instance->id} --auto-deploy=true to resume";
        }

        if ($auto->errorCode !== null) {
            $fields['Auto deploy error'] = $auto->errorCode;
        }

        return $fields;
    }
}
