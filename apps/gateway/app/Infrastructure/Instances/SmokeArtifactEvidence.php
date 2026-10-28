<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Data\Instances\InstanceSmokeResultData;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use DOMDocument;
use ZipArchive;

final readonly class SmokeArtifactEvidence
{
    public function __construct(private CommandActivityInputSanitizer $redactor) {}

    public function verify(string $archive, InstanceSmokeResultData $result): InstanceSmokeResultData
    {
        $result = clone $result;
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new GitHubActionsUnavailable(invalid: true);
        }
        try {
            $expanded = 0;
            if ($zip->numFiles > 128) {
                throw new GitHubActionsUnavailable(invalid: true);
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $expanded += $stat === false ? 16 * 1024 * 1024 + 1 : $stat['size'];
            }
            if ($expanded > 16 * 1024 * 1024) {
                throw new GitHubActionsUnavailable(invalid: true);
            }
            $target = json_decode($this->member($zip, 'storage/framework/testing/smoke-target.json', 4096), true,
                flags: JSON_THROW_ON_ERROR);
            foreach (['commit' => $result->commit, 'deployment_id' => (string) $result->deploymentId, 'base_url' => $result
                ->baseUrl, 'run_id' => (string) $result->runId, 'run_attempt' => (string) $result->runAttempt] as $key => $value) {
                if (! is_array($target) || ($target[$key] ?? null) !== $value) {
                    throw new GitHubActionsUnavailable(invalid: true);
                }
            }
            $xml = $this->member($zip, 'storage/framework/testing/smoke.xml', 1024 * 1024);
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                if (! mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0")
                    || stripos($xml, '<!DOCTYPE') !== false
                    || ! $document->loadXML($xml, LIBXML_NONET | LIBXML_NO_XXE) || $document->doctype !== null
                    || strcasecmp($document->encoding ?? 'UTF-8', 'UTF-8') !== 0 || ! in_array($document->documentElement
                        ?->tagName, ['testsuite', 'testsuites'], true)) {
                    throw new GitHubActionsUnavailable(invalid: true);
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $result->tests = $document->getElementsByTagName('testcase')->length;
            foreach (['failure' => 'failures', 'error' => 'errors', 'skipped' => 'skipped'] as $tag => $property) {
                $result->{$property} = $document->getElementsByTagName($tag)->length;
            }
            foreach ($document->getElementsByTagName('*') as $suite) {
                if (! in_array($suite->tagName, ['testsuite', 'testsuites'], true)) {
                    continue;
                }
                foreach (['tests' => 'testcase', 'failures' => 'failure', 'errors' => 'error',
                    'skipped' => 'skipped'] as $attribute => $tag) {
                    if ($suite->hasAttribute($attribute) && (! ctype_digit($suite->getAttribute($attribute)) || (int) $suite
                        ->getAttribute($attribute) !== $suite->getElementsByTagName($tag)->length)) {
                        throw new GitHubActionsUnavailable(invalid: true);
                    }
                }
            }
            $result->output = mb_strcut($this->redactor->redactText($document->textContent), 0, 65536, 'UTF-8');
            $result->state = match (true) {
                $result->failures > 0 || $result->errors > 0 => 'failed',
                $result->tests > 0 && $result->skipped === 0 && $result->conclusion === 'success' => 'passed',
                default => 'unknown',
            };

            return $result;
        } finally {
            $zip->close();
        }
    }

    private function member(ZipArchive $zip, string $name, int $limit): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > $limit || $stat['size'] < 1) {
            throw new GitHubActionsUnavailable(invalid: true);
        }
        $content = $zip->getFromName($name, $limit + 1);
        if ($content === false || strlen($content) !== $stat['size']) {
            throw new GitHubActionsUnavailable(invalid: true);
        }

        return $content;
    }
}
