<?php

declare(strict_types=1);

namespace App\Librarian;

use stdClass;

final class JsonMetadataStructureInspector
{
    /**
     * @return list<string>
     */
    public function emptyMetaPaths(stdClass|array $value, string $prefix = ''): array
    {
        if ($value instanceof stdClass) {
            return $this->emptyMetaPathsForObject($value, $prefix);
        }

        $paths = [];

        foreach (array_keys($value) as $key) {
            $structure = $this->jsonStructure($value[$key]);

            if ($structure !== null) {
                array_push($paths, ...$this->emptyMetaPaths($structure, $prefix));
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function emptyMetaPathsForObject(stdClass $object, string $prefix): array
    {
        $paths = [];

        $properties = get_object_vars($object);

        foreach (array_keys($properties) as $property) {
            $propertyName = (string) $property;
            $path = $prefix === '' ? $propertyName : "{$prefix}.{$propertyName}";

            if ($propertyName === 'meta' && $this->isEmptyObject($properties[$property])) {
                $paths[] = $path;

                continue;
            }

            $structure = $this->jsonStructure($properties[$property]);

            if ($structure !== null) {
                array_push($paths, ...$this->emptyMetaPaths($structure, $path));
            }
        }

        return $paths;
    }

    /**
     * @return stdClass|array<array-key, mixed>|null
     */
    private function jsonStructure(mixed $value): stdClass|array|null
    {
        if ($value instanceof stdClass || is_array($value)) {
            return $value;
        }

        return null;
    }

    private function isEmptyObject(mixed $value): bool
    {
        return $value instanceof stdClass && get_object_vars($value) === [];
    }
}
