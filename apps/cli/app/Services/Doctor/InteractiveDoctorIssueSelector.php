<?php

declare(strict_types=1);

namespace App\Services\Doctor;

final class InteractiveDoctorIssueSelector
{
    /**
     * @param  array<string, mixed>  $probe
     * @param  callable(string, list<string>, string): string  $ask
     * @param  callable(string): void  $write
     * @return array{restore: list<array<string, mixed>>, adopt: list<array<string, mixed>>}
     */
    public function select(array $probe, callable $ask, callable $write): array
    {
        $selected = [
            'restore' => [],
            'adopt' => [],
        ];

        foreach ($this->issues($probe) as $issue) {
            if (! $this->isActionable($issue)) {
                continue;
            }

            while (true) {
                $answer = $ask($this->prompt($issue), $this->choices($issue), 'skip');

                if ($answer === 'details') {
                    $this->writeDetails($issue, $write);

                    continue;
                }

                if ($answer === 'restore' || $answer === 'adopt') {
                    $selected[$answer][] = $issue;
                }

                break;
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $doctor
     * @return list<array<string, mixed>>
     */
    public function issues(array $doctor): array
    {
        $issues = $doctor['issues'] ?? [];

        if (! is_array($issues)) {
            return [];
        }

        return array_values(array_filter($issues, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function isActionable(array $issue): bool
    {
        return ($issue['restorable'] ?? false) === true || ($issue['adoptable'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return list<string>
     */
    private function choices(array $issue): array
    {
        $choices = [];

        if (($issue['restorable'] ?? false) === true) {
            $choices[] = 'restore';
        }

        if (($issue['adoptable'] ?? false) === true) {
            $choices[] = 'adopt';
        }

        $choices[] = 'skip';

        if ($this->details($issue) !== []) {
            $choices[] = 'details';
        }

        return $choices;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function prompt(array $issue): string
    {
        return 'Resolve '.$this->summary($issue);
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function summary(array $issue): string
    {
        foreach (['summary', 'code', 'key'] as $key) {
            $value = $issue[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'doctor finding';
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function details(array $issue): array
    {
        $details = $issue['details'] ?? $issue['detail'] ?? [];

        return is_array($details) ? $details : [];
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  callable(string): void  $write
     */
    private function writeDetails(array $issue, callable $write): void
    {
        foreach ($this->details($issue) as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $write($key.': '.$this->humanValue($value));
        }
    }

    private function humanValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
