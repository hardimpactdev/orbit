<?php

declare(strict_types=1);

namespace App\Domain\Instances;

/** The command that owns a removal: destroy deletes the source, unregister keeps it (docs/reference/instance-removal.md). */
enum InstanceRemovalKind: string
{
    case Destroy = 'destroy';
    case Unregister = 'unregister';

    /**
     * The removal kind that an admission input records; only an unregister input carries `unregister`.
     *
     * @param  array<string,mixed>  $input
     */
    public static function ofInput(array $input): self
    {
        return ($input['unregister'] ?? false) === true ? self::Unregister : self::Destroy;
    }

    public function operationKind(): string
    {
        return 'instance.'.$this->value;
    }
}
