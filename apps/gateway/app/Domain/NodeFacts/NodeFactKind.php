<?php

declare(strict_types=1);

namespace App\Domain\NodeFacts;

/**
 * The kinds of fact a Node agent reports, each with the validation rules of its `data`. The agent only observes; the
 * Gateway answers reads such as Doctor from the stored facts.
 */
enum NodeFactKind: string
{
    /** Operating system, architecture and filesystem space. */
    case Host = 'host';

    /** Systemd units and Docker containers the watch list names or the agent owns, with their state. */
    case Units = 'units';

    /** Free space is stored in whole steps of this size, so ordinary disk use is not a change to write. */
    public const int FreeStepBytes = 268_435_456;

    /** @return array<string, list<string>|string> */
    public function rules(): array
    {
        return match ($this) {
            self::Host => [
                'os' => ['required', 'string', 'max:64'],
                'arch' => ['required', 'string', 'max:32'],
                'filesystems' => ['present', 'array', 'list', 'max:64'],
                'filesystems.*.mount' => ['required', 'string', 'starts_with:/', 'max:4096'],
                'filesystems.*.size' => ['required', 'integer', 'min:0'],
                'filesystems.*.free' => ['required', 'integer', 'min:0'],
            ],
            self::Units => [
                'docker' => ['required', 'string', 'in:available,absent'],
                'units' => ['present', 'array', 'list', 'max:4096'],
                'units.*.name' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9@._:-]+\z/'],
                'units.*.runtime' => ['required', 'string', 'in:systemd,docker'],
                'units.*.state' => ['required', 'string', 'max:32', 'regex:/\A[a-z-]+\z/'],
            ],
        };
    }

    /**
     * The data as the Gateway stores it. A host's free space moves with every log line, so it is rounded down to
     * {@see self::FreeStepBytes}; the digest of the rounded data then changes only when the space really changes.
     *
     * @param  array<string, mixed>  $data  validated by {@see self::rules()}
     * @return array<string, mixed>
     */
    public function stored(array $data): array
    {
        if ($this !== self::Host || ! is_array($data['filesystems'] ?? null)) {
            return $data;
        }
        $data['filesystems'] = array_map(
            static fn (mixed $filesystem): mixed => is_array($filesystem) && is_int($filesystem['free'] ?? null)
                ? array_replace($filesystem, ['free' => intdiv($filesystem['free'], self::FreeStepBytes) * self::FreeStepBytes])
                : $filesystem,
            $data['filesystems'],
        );

        return $data;
    }
}
