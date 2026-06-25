<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Models\Node;
use App\Services\Doctor\DoctorReportRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

#[Signature(
    'orbit:internal:doctor-fleet-probe-node
    {--node= : Target node name}
    {--families=* : Doctor families to probe}
    {--key= : Optional issue key filter}',
)]
#[Description('Run a single-node doctor probe for fleet worker subprocesses')]
final class DoctorFleetProbeNodeCommand extends Command
{
    public function handle(DoctorReportRunner $runner): int
    {
        $nodeName = $this->stringOption('node');

        if ($nodeName === null || trim($nodeName) === '') {
            $this->components->error('The --node option is required.');

            return self::FAILURE;
        }

        $node = Node::query()->where('name', $nodeName)->first();

        if (! $node instanceof Node) {
            $this->components->error("Node '{$nodeName}' was not found.");

            return self::FAILURE;
        }

        $familyOptions = $this->option('families');
        $familyOptions = is_array($familyOptions) ? $familyOptions : [];

        /** @var list<string> $families */
        $families = array_values(array_filter(
            $familyOptions,
            static fn (mixed $family): bool => is_string($family) && trim($family) !== '',
        ));

        $key = $this->stringOption('key');
        $report = $runner->probeFleetTargetReport(
            node: $node,
            families: $families,
            key: $key,
            onFamilyProgress: function (
                string $family,
                string $phase,
                array $familyIssues,
                ?int $completed,
                ?int $total,
            ): void {
                if ($phase !== 'running' && $phase !== 'done') {
                    return;
                }

                $progress = [
                    'family' => $family,
                    'phase' => $phase,
                ];

                if ($completed !== null) {
                    $progress['completed'] = $completed;
                }

                if ($total !== null) {
                    $progress['total'] = $total;
                }

                try {
                    $this->output->writeln(json_encode(['progress' => $progress], JSON_THROW_ON_ERROR));
                } catch (JsonException) {
                    return;
                }
            },
        );

        try {
            $this->output->writeln(json_encode(['report' => $report], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            $this->components->error('Failed to encode doctor fleet probe report.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
