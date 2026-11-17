<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Collection;
use Laravel\Boost\Install\GuidelineComposer;
use Orbit\Boost\GuidelineSections;
use RuntimeException;

final class GatewayGuidelineComposer extends GuidelineComposer
{
    /** @return Collection<string, array<array-key, mixed>> */
    public function resolvedGuidelines(): Collection
    {
        return parent::resolvedGuidelines()->map($this->replaceScopedGuidance(...));
    }

    public function compose(): string
    {
        $guidelines = parent::compose();
        $boostSectionPattern = '/=== boost rules ===\R\R.*?(?=\R\R=== [^\r\n]+ rules ===|\z)/s';

        if (preg_match_all($boostSectionPattern, $guidelines) !== 1) {
            throw new RuntimeException(
                'Boost section changed. Update the Gateway guidance transformation before regenerating.',
            );
        }

        $boostGuidance = <<<'MARKDOWN'
            === boost rules ===

            # Laravel Boost

            - Before editing, open `.ai/rules/index.md`, read rules matching the paths in scope, and search `.ai/rules` for relevant terms. Required Guidance Bootstrap above owns missing-guidance recovery.
            - Activate the relevant installed skill for the domain. Use MCP tools only when the active client exposes them; otherwise use repository commands and files.
            - Confirm installed package versions before relying on their APIs. Use `search-docs` for version-specific Laravel APIs when available; copy-only edits need no package lookup.
            - Run `composer guidance:update` after changing guidance sources or Boost configuration. Keep durable rules in their existing owner, with matching path coverage in the index.
            MARKDOWN;
        $guidelines = preg_replace($boostSectionPattern, $boostGuidance, $guidelines);

        if (! is_string($guidelines)) {
            throw new RuntimeException('Unable to compose the Gateway Boost guidance.');
        }

        return GuidelineSections::withoutRemoved($guidelines);
    }

    /**
     * @param  array<array-key, mixed>  $guideline
     * @return array<array-key, mixed>
     */
    private function replaceScopedGuidance(array $guideline): array
    {
        if (! array_key_exists(key: 'scoped', array: $guideline) || ! is_array($guideline['scoped'])) {
            return $guideline;
        }

        $guideline['scoped'] = array_map(
            static function (mixed $block): mixed {
                if (! is_array($block) || ! is_string($block['body'] ?? null)) {
                    return $block;
                }

                $block['body'] = str_replace(
                    [
                        '- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.',
                        '- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.',
                        '- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.',
                        '- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.',
                        '- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.',
                    ],
                    [
                        '- Keep the versioned Gateway API consistent with its existing typed Spatie Data response objects. Do not introduce Eloquent Resources beside the established contract without an explicit API migration.',
                        '- Keep models focused on persisted control-plane state. Add a factory or seeder only when an executing test or explicit bootstrap workflow needs it.',
                        '- Run the tests that cover the change with stock Pest. Pass a file path or `--filter=testName` to `vendor/bin/pest --compact`.',
                        "- Run the project's full suite with `composer test`.",
                        '- After those tests pass, follow the root `AGENTS.md` check policy for task and non-task work. Follow the root contributor guide for CI and independent review.',
                    ],
                    $block['body'],
                );

                return $block;
            },
            $guideline['scoped'],
        );

        return $guideline;
    }
}
