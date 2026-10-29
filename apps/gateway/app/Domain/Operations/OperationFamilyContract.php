<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Support\ValidatedData;
use LogicException;

/** Loads a reviewed build artifact; it does not register or admit a family. */
final readonly class OperationFamilyContract
{
    public function __construct(private OperationFamilyCatalogue $catalogue) {}

    /**
     * The expected digest comes from a code-owned release pin, never an Operation or run input.
     *
     * @return array<string, mixed>
     */
    public function load(string $path, string $expectedSha256): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1
            || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new LogicException('Family artifact does not match its release pin.');
        }
        $contract = ValidatedData::object(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR));
        if (($contract['schema'] ?? null) !== 'orbit.family-contract/v1'
            || ($contract['catalogue'] ?? null) !== $this->catalogue->digest()) {
            throw new LogicException('Family catalogue does not match this Gateway release.');
        }
        $this->catalogue->projection(ValidatedData::string($contract['projection'] ?? null));
        $supported = ValidatedData::object(json_decode($this->catalogue->json(), true, flags: JSON_THROW_ON_ERROR));
        foreach (ValidatedData::stringList($contract['invariants'] ?? null) as $name) {
            if (! in_array($name, ValidatedData::stringList($supported['invariants']), true)) {
                throw new LogicException('Unknown family invariant.');
            }
        }
        $commands = ValidatedData::object($contract['commands'] ?? null);
        $registered = ValidatedData::object($supported['commands']);
        foreach ($commands as $declaration) {
            $command = ValidatedData::object($declaration);
            $transport = ValidatedData::string($command['transport'] ?? null);
            $entries = $registered[$transport] ?? null;
            if (! is_array($entries)) {
                throw new LogicException('Unknown family transport.');
            }
            $known = array_column($entries, 'name');
            foreach (ValidatedData::stringList($command['names'] ?? null) as $name) {
                if (! in_array($name, $known, true)) {
                    throw new LogicException('Unknown family command.');
                }
            }
        }
        if ($commands !== [] && $supported['native_methods'] === []) {
            throw new LogicException('No native family command methods are registered.');
        }

        return $contract;
    }
}
