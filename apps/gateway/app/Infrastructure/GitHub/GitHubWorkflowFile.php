<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Whether a GitHub Actions workflow file can ask for a self-hosted runner, for the CI runner view
 * (docs/reference/ci-runner-view.md#the-workflow-filter). Only a file that proves every job asks for labels without `self-hosted`
 * answers no: a job that calls another workflow, a `runs-on` built from an expression, and a file that does not parse all answer yes.
 */
final class GitHubWorkflowFile
{
    public static function canAskForSelfHosted(string $text): bool
    {
        try {
            $workflow = Yaml::parse($text);
        } catch (ParseException) {
            return true;
        }

        $jobs = is_array($workflow) ? ($workflow['jobs'] ?? null) : null;
        if (! is_array($jobs)) {
            return true;
        }

        foreach ($jobs as $job) {
            if (! is_array($job) || isset($job['uses']) || ! isset($job['runs-on'])) {
                return true;
            }

            $labels = $job['runs-on'];
            if (is_array($labels) && ! array_is_list($labels)) {
                $labels = $labels['labels'] ?? [];
            }

            foreach (is_array($labels) ? $labels : [$labels] as $label) {
                if (! is_string($label) || str_contains($label, '${{') || strtolower(trim($label)) === 'self-hosted') {
                    return true;
                }
            }
        }

        return false;
    }
}
