<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class ProductCodeNamespaceRule implements CommandDocsLintRule
{
    /**
     * @var array<string, string>
     */
    private const array PLURAL_PREFIXES = [
        'apps' => 'app',
        'firewall_rules' => 'firewall_rule',
        'nodes' => 'node',
        'processes' => 'process',
        'proxy_routes' => 'proxy_route',
        'schedules' => 'schedule',
        'tools' => 'tool',
        'workspaces' => 'workspace',
    ];

    /**
     * @var array<string, true>
     */
    private const array ALLOWED_STORAGE_FIELDS = [
        'nodes.host' => true,
        'nodes.platform' => true,
        'nodes.public_ipv4_address' => true,
        'nodes.public_ipv6_address' => true,
        'nodes.tld' => true,
        'nodes.user' => true,
        'nodes.wg_ip' => true,
    ];

    public function id(): string
    {
        return 'command_docs.product_code_namespace';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->markdownFiles($familyDirectory) as $file) {
                foreach ($this->pluralProductCodes($context->read($file)) as $code => $singularPrefix) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: "Product code `{$code}` must use singular prefix `{$singularPrefix}.`; plural dotted names are allowed only for explicit storage fields.",
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function pluralProductCodes(string $contents): array
    {
        $codes = [];
        $prefixPattern = implode('|', array_map(preg_quote(...), array_keys(self::PLURAL_PREFIXES)));

        preg_match_all(
            '/(?<![a-z0-9_.-])(?<prefix>'.$prefixPattern.')\.(?<condition>[a-z][a-z0-9_]*)(?![a-z0-9_.-])/i',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $prefix = strtolower($match['prefix']);
            $code = "{$prefix}.{$match['condition']}";

            if (isset(self::ALLOWED_STORAGE_FIELDS[$code])) {
                continue;
            }

            $codes[$code] = self::PLURAL_PREFIXES[$prefix];
        }

        ksort($codes);

        return $codes;
    }
}
