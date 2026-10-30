<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Shared\ResourceOperationException;

final readonly class InstanceEnvironmentRenderer
{
    private const string DomainPlaceholder = '{{instance.domain}}';

    private const string EnvironmentPlaceholder = '{{instance.environment}}';

    /** @param array<string, string> $values */
    public function render(
        InstanceEnvironmentContext $context,
        #[\SensitiveParameter]
        array $values,
    ): string {
        $rendered = '';
        foreach ($this->resolvedValues($context, $values) as $key => $value) {
            $rendered .= $key.'='.self::quote($value)."\n";
        }

        return $rendered;
    }

    /** @param array<string,string> $values
     * @return array<string,string>
     */
    public function resolvedValues(InstanceEnvironmentContext $context, #[\SensitiveParameter] array $values): array
    {
        if (! in_array($context->environment, ['development', 'production'], true)) {
            $this->referenceUnavailable();
        }
        if ($context->routeDomain === '' || ($context->routeDomain === null && array_any(
            $values, static fn (string $value): bool => str_contains($value, self::DomainPlaceholder),
        ))) {
            $this->referenceUnavailable();
        }
        ksort($values, SORT_STRING);
        $bytes = 0;
        foreach ($values as $key => $value) {
            $resolved = str_replace([self::DomainPlaceholder, self::EnvironmentPlaceholder],
                [$context->routeDomain ?? '', $context->environment], $value);
            if (str_contains($resolved, '{{') || str_contains($resolved, '}}')) {
                $this->referenceUnavailable();
            }
            $bytes += strlen($key) + strlen(self::quote($resolved)) + 2;
            if ($bytes > InstanceEnvironmentValidator::MaximumFileBytes) {
                throw new ResourceOperationException('env.configuration_invalid', 'The complete Instance environment configuration is invalid.',
                    details: ['key' => $key, 'rule' => InstanceEnvironmentValidator::RuleFileSize]);
            }
            $values[$key] = $resolved;
        }

        return $values;
    }

    /** Quote one value as a double-quoted dotenv value. */
    public static function quote(#[\SensitiveParameter] string $value): string
    {
        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
        ]).'"';
    }

    private function referenceUnavailable(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.reference_unavailable',
            message: 'The stored Instance environment configuration has an unavailable reference.',
            status: 409,
        );
    }
}
