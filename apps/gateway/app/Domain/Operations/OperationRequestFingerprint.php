<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use App\Support\StrictJson;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class OperationRequestFingerprint
{
    /** @param array<string, mixed> $parameters */
    public function make(?Node $caller, ?string $command, array $parameters, #[\SensitiveParameter] string $content, StartsOperation $start): string
    {
        return hash('sha256', json_encode([
            ...($start->bindCaller ? ['caller_node_id' => $caller?->id, 'command' => $command] : []),
            'target' => array_map(static fn (mixed $value): mixed => $value instanceof Model ? $value->getKey() : $value, $parameters),
            'input' => $this->input($content, $start->maxJsonBytes, $start->secretInputs),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param list<string> $secrets */
    private function input(#[\SensitiveParameter] string $content, ?int $limit, array $secrets): string
    {
        try {
            if ($limit !== null) {
                $input = StrictJson::decode($content, $limit);
            } else {
                $input = json_decode($content, flags: JSON_THROW_ON_ERROR);
                if ($input instanceof stdClass) {
                    new TopLevelJsonObjectInspector()->inspect($content, array_keys(get_object_vars($input)));
                }
            }

            foreach ($secrets as $path) {
                $keys = explode('.', $path);
                $last = array_pop($keys);
                $object = $input;
                foreach ($keys as $key) {
                    $object = $object instanceof stdClass ? ($object->{$key} ?? null) : null;
                }
                if ($object instanceof stdClass) {
                    unset($object->{$last});
                }
            }

            return json_encode($this->canonical($input), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException|InvalidArgumentException|UnexpectedValueException) {
            // Invalid input still conflicts with an existing key before the Form Request validates it.
            return 'invalid-json:'.hash('sha256', $content);
        }
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map($this->canonical(...), $properties);
        }

        return is_array($value) ? array_map($this->canonical(...), $value) : $value;
    }
}
