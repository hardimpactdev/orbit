<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\Rules\Prose\MarkdownFileWalker;
use OrbitDocsLinter\Rules\Prose\ProseSegmenter;

/**
 * Compound noun stacking ("gateway-owned development DNS mapping", "first-gateway
 * bootstrap convergence") forces the reader to hold three or four modifiers in
 * working memory before reaching the head noun. Laravel and other clear technical
 * docs almost never do this — they decompose the same idea across a sentence.
 *
 * The rule anchors on an invented hyphenated compound (a hyphenated word *not*
 * in the accepted-compound allowlist) and counts how many additional non-stop,
 * non-punctuated tokens follow it before the chain breaks. The chain must end
 * on a token that could plausibly be a head noun, so the total reported "stack
 * size" is `1 (anchor) + N modifiers + 1 (head)`.
 *
 * Threshold: a stack of 4+ tokens (anchor + 2 modifiers + head) is the warning
 * line. Below that, a hyphenated compound plus one short modifier
 * ("gateway-tracked configuration", "gateway-managed private network") is fine.
 * Chains are capped at 5 tokens to bound false positives across clause
 * boundaries the splitter missed.
 */
final class CompoundNounStackRule implements CommandDocsLintRule
{
    private const WARN_THRESHOLD = 4;

    private const MAX_CHAIN_LENGTH = 5;

    /**
     * Words that end a noun phrase. Hitting one stops the chain at the previous
     * token (which is considered the head noun if the chain is long enough).
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'this', 'that', 'these', 'those',
        'each', 'every', 'all', 'any', 'some', 'no', 'both', 'either', 'neither',
        'another', 'such', 'same', 'one', 'two', 'three', 'four', 'five', 'six',
        'many', 'most', 'few', 'several',
        'and', 'or', 'but', 'nor', 'so', 'yet',
        'of', 'in', 'on', 'at', 'by', 'for', 'from', 'to', 'with', 'as', 'into', 'onto',
        'over', 'under', 'above', 'below', 'between', 'through', 'during',
        'against', 'without', 'within', 'across', 'about', 'around',
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'has', 'have', 'had',
        'will', 'would', 'should', 'may', 'might', 'must', 'can', 'could',
        'do', 'does', 'did', 'not', 'no',
        'when', 'where', 'while', 'after', 'before', 'because', 'if', 'unless', 'until',
        'i', 'you', 'we', 'they', 'he', 'she', 'it',
        'its', 'their', 'your', 'our', 'his', 'her', 'my',
        'use', 'uses', 'using', 'used',
        'see', 'sees', 'seeing',
        'turn', 'turns', 'turning', 'turned',
        'make', 'makes', 'making', 'made',
        'run', 'runs', 'running', 'ran',
        'render', 'renders', 'rendering', 'rendered',
        'serve', 'serves', 'serving', 'served',
        'hold', 'holds', 'holding', 'held',
        'flow', 'flows', 'flowing', 'flowed',
        'expose', 'exposes', 'exposing', 'exposed',
        'apply', 'applies', 'applying', 'applied',
        'connect', 'connects', 'connecting', 'connected',
        'store', 'stores', 'storing', 'stored',
        'come', 'comes', 'coming', 'came',
        'know', 'knows', 'knowing', 'knew',
        'know', 'know',
        'go', 'goes', 'going', 'gone', 'went',
        'get', 'gets', 'getting', 'got',
        'give', 'gives', 'giving', 'gave',
        'take', 'takes', 'taking', 'took',
        'fall', 'falls', 'falling', 'fell',
        'live', 'lives', 'living', 'lived',
        'write', 'writes', 'writing', 'wrote', 'written',
        'read', 'reads', 'reading',
        'install', 'installs', 'installing', 'installed',
        'see', 'see',
        'manage', 'manages', 'managing', 'managed',
        'supervise', 'supervises', 'supervising', 'supervised',
        'isolate', 'isolates', 'isolating', 'isolated',
        'split', 'splits', 'splitting',
        'route', 'routes', 'routing', 'routed',
        'stream', 'streams', 'streaming', 'streamed',
        'send', 'sends', 'sending', 'sent',
        'receive', 'receives', 'receiving', 'received',
        'process', 'processes', 'processing', 'processed',
        'handle', 'handles', 'handling', 'handled',
        'create', 'creates', 'creating', 'created',
        'remove', 'removes', 'removing', 'removed',
        'add', 'adds', 'adding', 'added',
        'set', 'sets', 'setting',
        'list', 'lists', 'listing', 'listed',
        'show', 'shows', 'showing', 'shown',
        'allow', 'allows', 'allowing', 'allowed',
        'enable', 'enables', 'enabling', 'enabled',
        'reach', 'reaches', 'reaching', 'reached',
        'mean', 'means', 'meaning', 'meant',
        'leave', 'leaves', 'leaving', 'left',
        'keep', 'keeps', 'keeping', 'kept',
        'find', 'finds', 'finding', 'found',
        'build', 'builds', 'building', 'built',
        'support', 'supports', 'supporting', 'supported',
        'execute', 'executes', 'executing', 'executed',
        'host', 'hosts', 'hosting', 'hosted',
        'mint', 'mints', 'minting', 'minted',
        'verify', 'verifies', 'verifying', 'verified',
        'bootstrap', 'bootstraps', 'bootstrapping', 'bootstrapped',
        'join', 'joins', 'joining', 'joined',
        'mount', 'mounts', 'mounting', 'mounted',
        'restart', 'restarts', 'restarting', 'restarted',
        'depend', 'depends', 'depending', 'depended',
        'belong', 'belongs', 'belonging', 'belonged',
        'short-circuit',
    ];

    /**
     * Established technical compounds. A hyphenated word matching one of these
     * is NOT treated as an "invented" hyphenation, so it does not anchor a
     * stack on its own.
     *
     * @var list<string>
     */
    private const ACCEPTED_COMPOUNDS = [
        'php-fpm',
        'wireguard',
        'cloudflare',
        'caddyfile',
        'sqlite',
        'systemd',
        'open-source',
        'end-to-end',
        'in-memory',
        'long-running',
        'server-sent',
        'cross-cutting',
        'opt-in',
        'opt-out',
        'real-time',
        'machine-readable',
        'human-readable',
        'first-party',
        'third-party',
        'read-only',
        'read-write',
        'non-interactive',
        'side-by-side',
        'top-level',
        'low-level',
        'high-level',
        'self-signed',
        'up-to-date',
    ];

    /**
     * Tokens that end a sentence or clause and must break the chain regardless
     * of how the surrounding words read. Stored as single characters so the
     * scanner can match them in one pass over the raw text.
     *
     * @var list<string>
     */
    private const BOUNDARY_CHARS = ['.', ',', ';', ':', '(', ')', '[', ']', '!', '?', '"', '“', '”', '—'];

    public function id(): string
    {
        return 'command_docs.compound_noun_stack';
    }

    public function group(): string
    {
        return 'prose';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach (MarkdownFileWalker::files($context) as $file) {
            $contents = $context->read($file);
            $paragraphs = ProseSegmenter::segment($contents);

            foreach ($paragraphs as $paragraph) {
                foreach ($this->stacksInText($paragraph['text']) as $stack) {
                    $findings[] = new CommandDocsLintFinding(
                        path: $context->relativePath($file),
                        ruleId: $this->id(),
                        message: sprintf(
                            'Compound noun phrase "%s" stacks %d modifiers before the head noun. Decompose into a sentence ("X that is Y by Z") instead.',
                            $stack['phrase'],
                            $stack['modifiers'],
                        ),
                        severity: CommandDocsLintSeverity::Warning,
                        line: $paragraph['line'],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array{phrase: string, modifiers: int}>
     */
    private function stacksInText(string $text): array
    {
        $clauses = $this->splitClauses($text);
        $stacks = [];

        foreach ($clauses as $clause) {
            foreach ($this->stacksInClause($clause) as $stack) {
                $stacks[] = $stack;
            }
        }

        return $stacks;
    }

    /**
     * @return list<string>
     */
    private function splitClauses(string $text): array
    {
        $pattern = '/['.preg_quote(implode('', self::BOUNDARY_CHARS), '/').']+/u';
        $clauses = preg_split($pattern, $text) ?: [];

        return array_values(array_filter(array_map('trim', $clauses)));
    }

    /**
     * @return list<array{phrase: string, modifiers: int}>
     */
    private function stacksInClause(string $clause): array
    {
        $tokens = $this->tokenize($clause);
        $stacks = [];

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (! $this->isInventedHyphenation($token)) {
                continue;
            }

            $chain = [$token];

            for ($next = $index + 1; $next < count($tokens); $next++) {
                $candidate = $tokens[$next];

                if ($this->isStopWord($candidate)) {
                    break;
                }

                $chain[] = $candidate;

                if (count($chain) >= self::MAX_CHAIN_LENGTH) {
                    break;
                }
            }

            if (count($chain) >= self::WARN_THRESHOLD) {
                $stacks[] = [
                    'phrase' => implode(' ', $chain),
                    'modifiers' => count($chain) - 1,
                ];
            }

            $index += count($chain) - 1;
        }

        return $stacks;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        preg_match_all('/[A-Za-z][A-Za-z\'-]*/', $text, $matches);

        return $matches[0];
    }

    private function isStopWord(string $token): bool
    {
        return in_array(strtolower($token), self::STOP_WORDS, true);
    }

    private function isInventedHyphenation(string $token): bool
    {
        if (! str_contains($token, '-')) {
            return false;
        }

        return ! in_array(strtolower($token), self::ACCEPTED_COMPOUNDS, true);
    }
}
