<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Shared\ResourceOperationException;

final readonly class InstanceEnvironmentRenderer
{
    private const string DomainPlaceholder = '{{instance.domain}}';

    private const string EnvironmentPlaceholder = '{{instance.environment}}';

    /** Only these non-secret Instance values may be copied into a browser-visible key. */
    private const array TemplateReferences = ['APP_URL', 'APP_NAME'];

    /** @param array<string, string> $values */
    public function render(
        InstanceEnvironmentContext $context,
        #[\SensitiveParameter]
        array $values,
    ): string {
        if (! in_array($context->environment, ['development', 'production'], true)) {
            $this->referenceUnavailable();
        }

        if (
            $context->routeDomain === ''
            || ($context->routeDomain === null && array_any(
                $values,
                static fn (string $value): bool => str_contains($value, self::DomainPlaceholder),
            ))
        ) {
            $this->referenceUnavailable();
        }

        ksort($values, SORT_STRING);
        $rendered = '';

        foreach ($values as $key => $value) {
            $resolved = str_replace(
                [self::DomainPlaceholder, self::EnvironmentPlaceholder],
                [$context->routeDomain ?? '', $context->environment],
                $value,
            );

            if (str_contains($resolved, '{{') || str_contains($resolved, '}}')) {
                $this->referenceUnavailable();
            }

            $rendered .= $key.'='.self::quote($resolved)."\n";

            if (strlen($rendered) > InstanceEnvironmentValidator::MaximumFileBytes) {
                throw new ResourceOperationException(
                    errorCode: 'env.configuration_invalid',
                    message: 'The complete Instance environment configuration is invalid.',
                    details: [
                        'key' => $key,
                        'rule' => InstanceEnvironmentValidator::RuleFileSize,
                    ],
                );
            }
        }

        return $rendered;
    }

    /**
     * Resolve only the non-secret allow-list. Keep every other value's existing serialization, including
     * values whose escaping differs between dotenv and systemd. Never rescan a replacement.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $template
     * @param  array<string, list<int>>  $referencePositions  byte offsets retained by the template parser
     * @return array<string, string>
     */
    public function resolveTemplateReferences(
        #[\SensitiveParameter] array $values,
        #[\SensitiveParameter] array $template,
        array $referencePositions = [],
    ): array {
        foreach ($template as $key => $value) {
            preg_match_all('/\$\{([A-Za-z0-9_.]+)\}/', $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $resolved = $value;
            foreach (array_reverse($matches) as $match) {
                $name = $match[1][0];
                $offset = $match[0][1];
                if (! in_array($name, self::TemplateReferences, true) || ! array_key_exists($name, $values)) {
                    continue;
                }
                if (isset($referencePositions[$key])
                    ? ! in_array($offset, $referencePositions[$key], true)
                    : ($offset > 0 && $value[$offset - 1] === '\\')) {
                    continue;
                }
                $resolved = substr_replace($resolved, $values[$name], $offset, strlen($match[0][0]));
            }
            // The existing double-quoted encoding has different escape rules in the three consumers.
            if (strpbrk($resolved, "\\\"\r\n$") === false) {
                $values[$key] = $resolved;
            }
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
