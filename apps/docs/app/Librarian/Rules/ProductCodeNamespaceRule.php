<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ProductCodeNamespaceRule implements GroupedRule
{
    /**
     * @var array<string, string>
     */
    private const array PLURAL_PREFIXES = [
        'instances' => 'instance',
        'apps' => 'app',
        'firewall_rules' => 'firewall_rule',
        'nodes' => 'node',
        'processes' => 'process',
        'proxy_routes' => 'proxy',
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

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                foreach ($this->pluralProductCodes(file_get_contents($file) ?: '') as $code => $singularPrefix) {
                    $findings[] = new Finding(
                        path: $this->docs->relativePath($file),
                        line: null,
                        severity: FindingSeverity::Error,
                        rule: 'command_docs.product_code_namespace',
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
