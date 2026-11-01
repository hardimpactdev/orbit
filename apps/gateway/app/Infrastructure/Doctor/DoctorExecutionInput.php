<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Add public stored SSH placement. Doctor needs no execution secrets. */
final readonly class DoctorExecutionInput
{
    public function __construct(private SwampSshExecutionInput $ssh) {}

    /** @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    public function forIntent(array $intent): array
    {
        $nodes = [];
        foreach (Arr::array($intent, 'nodes') as $value) {
            $node = ValidatedData::object($value);
            if (($node['managed'] ?? false) && ! ($node['parked'] ?? false)) {
                $node['ssh'] = $this->ssh->forNode(Node::query()->findOrFail(ValidatedData::integer($node['id'])))['target'];
            }
            $nodes[] = $node;
        }
        $intent['nodes'] = $nodes;

        return ['intent' => $intent];
    }
}
