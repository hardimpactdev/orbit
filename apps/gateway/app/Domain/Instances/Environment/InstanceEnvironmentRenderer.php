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
     * Resolve only values from the Project template, after the Instance's overrides. Literal replacements
     * are never scanned again. The stored result supplies both dotenv and systemd's EnvironmentFile.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $template
     * @return array<string, string>
     */
    public function resolveTemplateReferences(#[\SensitiveParameter] array $values, #[\SensitiveParameter] array $template): array
    {
        $resolved = array_diff_key($values, $template);
        $visiting = [];
        $resolve = function (string $key) use (&$resolve, &$resolved, &$visiting, $template): string {
            if (array_key_exists($key, $resolved)) {
                return $resolved[$key];
            }
            if (isset($visiting[$key])) {
                $this->referenceUnavailable();
            }
            $visiting[$key] = true;
            $value = preg_replace_callback('/(\\\\)?\$\{([A-Za-z0-9_.]+)\}/', function (array $match) use (&$resolve, $resolved, $template): string {
                if ($match[1] !== '') {
                    return substr($match[0], 1);
                }

                return array_key_exists($match[2], $template) || array_key_exists($match[2], $resolved)
                    ? $resolve($match[2]) : $match[0];
            }, $template[$key]) ?? throw new \LogicException('Template reference matching failed.');
            unset($visiting[$key]);

            return $resolved[$key] = $value;
        };
        foreach (array_keys($template) as $key) {
            $resolve($key);
        }

        return array_replace($values, $resolved);
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
