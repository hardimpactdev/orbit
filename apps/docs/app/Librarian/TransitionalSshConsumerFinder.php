<?php

declare(strict_types=1);

namespace App\Librarian;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class TransitionalSshConsumerFinder
{
    /** @var list<string> */
    private const array SOURCE_ROOTS = [
        'apps/gateway/app',
        'apps/cli/app',
    ];

    /** @var list<string> */
    private const array EDGE_PATTERNS = [
        'typed RemoteShell/RemoteExecutor/StartsRemoteShellProcesses run/start call',
        'SshCommandBuilder ssh/enforceForNode/scpTo/scpToNode call',
        'RemoteHostExecutor run/start call',
        'NodeTransportPreference',
        'withNodeTransportPreference',
        'X-Orbit-Node-Transport-Preference',
        'HTTP_X_ORBIT_NODE_TRANSPORT_PREFERENCE',
        'ssh_bootstrap_binary',
    ];

    /** @var list<string> */
    private const array CLI_SELECTOR_PATTERNS = [
        '--node-transport',
        'node-transport',
    ];

    /** @var list<string> */
    private const array TRANSPORT_SELECTOR_PATTERNS = [
        'NodeTransportPreference',
        'withNodeTransportPreference',
        'X-Orbit-Node-Transport-Preference',
        'HTTP_X_ORBIT_NODE_TRANSPORT_PREFERENCE',
    ];

    /** @var array<string, string> */
    private const array TYPE_PREFIXES = [
        'RemoteShell' => 'remote-shell',
        'RemoteExecutor' => 'remote-executor',
        'StartsRemoteShellProcesses' => 'remote-shell-processes',
        'RemoteHostExecutor' => 'remote-host-executor',
        'SshCommandBuilder' => 'ssh-builder',
    ];

    /** @var array<string, string> */
    private const array TYPE_IMPORTS = [
        'App\\Contracts\\RemoteShell' => 'remote-shell',
        'App\\Contracts\\StartsRemoteShellProcesses' => 'remote-shell-processes',
        'App\\Services\\RemoteShell\\RemoteExecutor' => 'remote-executor',
        'App\\Services\\RemoteShell\\RemoteHostExecutor' => 'remote-host-executor',
        'App\\Services\\RemoteShell\\SshCommandBuilder' => 'ssh-builder',
    ];

    /** @var array<string, string> */
    private const array SSH_BUILDER_METHODS = [
        'ssh' => 'ssh-builder.ssh',
        'enforceForNode' => 'ssh-builder.enforce-for-node',
        'scpTo' => 'ssh-builder.scp-to',
        'scpToNode' => 'ssh-builder.scp-to-node',
    ];

    /** @var list<string> */
    private const array EXCLUDED_PATHS = [
        'apps/gateway/app/Providers/AppServiceProvider.php',
        'apps/gateway/app/Contracts/RemoteShell.php',
        'apps/gateway/app/Contracts/StartsRemoteShellProcesses.php',
        'apps/gateway/app/Services/RemoteShell/RemoteExecutor.php',
        'apps/gateway/app/Services/RemoteShell/RunsInternalCommands.php',
        'apps/gateway/app/Services/RemoteShell/RemoteShellScriptComposer.php',
        'apps/gateway/app/Services/RemoteShell/SshCommandBuilder.php',
    ];

    /**
     * @return array<string, string>
     */
    public function find(): array
    {
        $files = [];
        $root = $this->repositoryRoot();

        foreach (self::SOURCE_ROOTS as $relativeSourceRoot) {
            $sourceRoot = "{$root}/{$relativeSourceRoot}";
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $this->relativePath($file->getPathname(), $root);

                if (in_array($path, self::EXCLUDED_PATHS, strict: true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (! is_string($contents)) {
                    continue;
                }

                if (! $this->isConsumer($path, $contents) && ! $this->hasLaneMarker($contents)) {
                    continue;
                }

                $files[$path] = $contents;
            }
        }

        ksort($files);

        return $files;
    }

    /** @return list<string> */
    public function sourceRoots(): array
    {
        return self::SOURCE_ROOTS;
    }

    /** @return list<string> */
    public function edgePatterns(): array
    {
        return self::EDGE_PATTERNS;
    }

    /** @return list<string> */
    public function cliSelectorPatterns(): array
    {
        return self::CLI_SELECTOR_PATTERNS;
    }

    public function isConsumer(string $path, string $contents): bool
    {
        return $this->edgesIn($path, $contents) !== [];
    }

    /**
     * @return list<array{path: string, call_line: int, edge: string}>
     */
    public function edgesIn(string $path, string $contents): array
    {
        $edges = $this->selectorEdges($path, $contents);
        $codeByLine = $this->codeByLine($contents);
        $code = implode('', $codeByLine);
        $typePrefixes = $this->typePrefixes($code);
        $receivers = $this->typedReceivers($code, $typePrefixes);

        foreach ($codeByLine as $line => $lineCode) {
            $callCandidate = [
                'path' => $path,
                'line' => $line,
                'line_code' => $lineCode,
                'call_code' => $this->callCodeFromLine($codeByLine, $line),
            ];

            $this->appendDirectContainerEdges($edges, $typePrefixes, $callCandidate);
            $this->appendTypedReceiverEdges($edges, $receivers, $callCandidate);
        }

        $unique = [];

        foreach ($edges as $edge) {
            $unique["{$edge['path']}:{$edge['call_line']}:{$edge['edge']}"] = $edge;
        }

        $edges = array_values($unique);
        usort(
            $edges,
            static fn (array $left, array $right): int => (
                [
                    $left['path'],
                    $left['call_line'],
                    $left['edge'],
                ] <=> [
                    $right['path'],
                    $right['call_line'],
                    $right['edge'],
                ]
            ),
        );

        return $edges;
    }

    /**
     * @return list<array{path: string, call_line: int, edge: string}>
     */
    private function selectorEdges(string $path, string $contents): array
    {
        $edges = [];

        foreach (explode("\n", $contents) as $index => $line) {
            $callLine = $index + 1;

            if (array_any(self::TRANSPORT_SELECTOR_PATTERNS, static fn (string $pattern): bool => str_contains(
                $line,
                $pattern,
            ))) {
                $edges[] = [
                    'path' => $path,
                    'call_line' => $callLine,
                    'edge' => 'public-node-transport-selector',
                ];
            }

            if (
                str_starts_with($path, 'apps/cli/app/Commands/')
                && array_any(self::CLI_SELECTOR_PATTERNS, static fn (string $pattern): bool => str_contains(
                    $line,
                    $pattern,
                ))
            ) {
                $edges[] = [
                    'path' => $path,
                    'call_line' => $callLine,
                    'edge' => 'public-node-transport-selector',
                ];
            }

            if (str_contains($line, 'ssh_bootstrap_binary')) {
                $edges[] = [
                    'path' => $path,
                    'call_line' => $callLine,
                    'edge' => 'ssh-bootstrap-binary',
                ];
            }
        }

        return $edges;
    }

    /**
     * @param  list<array{path: string, call_line: int, edge: string}>  $edges
     * @param  array<string, string>  $typePrefixes
     * @param  array{path: string, line: int, line_code: string, call_code: string}  $callCandidate
     */
    private function appendDirectContainerEdges(
        array &$edges,
        array $typePrefixes,
        array $callCandidate,
    ): void {
        if (! str_contains($callCandidate['line_code'], 'app')) {
            return;
        }

        foreach ($typePrefixes as $type => $prefix) {
            $typePattern = preg_quote(str: $type, delimiter: '/');

            if ($prefix === 'ssh-builder') {
                foreach (self::SSH_BUILDER_METHODS as $method => $edge) {
                    if (
                        preg_match("/app\\({$typePattern}::class,?\\)->{$method}\\(/", $callCandidate['call_code'])
                        !== 1
                    ) {
                        continue;
                    }

                    $edges[] = [
                        'path' => $callCandidate['path'],
                        'call_line' => $callCandidate['line'],
                        'edge' => $edge,
                    ];
                }

                continue;
            }

            $matches = [];

            if (
                preg_match("/app\\({$typePattern}::class,?\\)->(run|start)\\(/", $callCandidate['call_code'], $matches)
                !== 1
            ) {
                continue;
            }

            $edges[] = [
                'path' => $callCandidate['path'],
                'call_line' => $callCandidate['line'],
                'edge' => "{$prefix}.{$matches[1]}",
            ];
        }
    }

    /**
     * @param  list<array{path: string, call_line: int, edge: string}>  $edges
     * @param  array<string, string>  $receivers
     * @param  array{path: string, line: int, line_code: string, call_code: string}  $callCandidate
     */
    private function appendTypedReceiverEdges(
        array &$edges,
        array $receivers,
        array $callCandidate,
    ): void {
        foreach ($receivers as $receiver => $type) {
            $receiverNamePattern = preg_quote(str: $receiver, delimiter: '/');
            $receiverPattern = '(?:\\$this->|\\$)'.$receiverNamePattern.'->';
            $startsOnLine =
                preg_match(
                    '/(?:\\$this->|\\$)'.$receiverNamePattern.'(?:->|$)/',
                    $callCandidate['line_code'],
                ) === 1
                || str_ends_with($callCandidate['line_code'], '$this');

            if (! $startsOnLine) {
                continue;
            }

            if ($type === 'ssh-builder') {
                foreach (self::SSH_BUILDER_METHODS as $method => $edge) {
                    if (preg_match("/{$receiverPattern}{$method}\\(/", $callCandidate['call_code']) !== 1) {
                        continue;
                    }

                    $edges[] = [
                        'path' => $callCandidate['path'],
                        'call_line' => $callCandidate['line'],
                        'edge' => $edge,
                    ];
                }

                continue;
            }

            $matches = [];

            if (preg_match("/{$receiverPattern}(run|start)\\(/", $callCandidate['call_code'], $matches) === 1) {
                $edges[] = [
                    'path' => $callCandidate['path'],
                    'call_line' => $callCandidate['line'],
                    'edge' => "{$type}.{$matches[1]}",
                ];
            }
        }
    }

    /**
     * @param  array<string, string>  $typePrefixes
     * @return array<string, string>
     */
    private function typedReceivers(string $code, array $typePrefixes): array
    {
        $receivers = [];

        foreach ($typePrefixes as $type => $prefix) {
            $matches = [];
            $matchCount = preg_match_all(
                '/'.preg_quote(str: $type, delimiter: '/').'\$(\w+)/',
                $code,
                $matches,
            );

            if ($matchCount === false || $matchCount < 1) {
                continue;
            }

            foreach ($matches[1] as $receiver) {
                $receivers[$receiver] = $prefix;
            }
        }

        return $receivers;
    }

    /** @return array<string, string> */
    private function typePrefixes(string $code): array
    {
        $typePrefixes = self::TYPE_PREFIXES;

        foreach (self::TYPE_IMPORTS as $import => $prefix) {
            $matches = [];
            $pattern = '/use'.preg_quote(str: $import, delimiter: '/').'as(\w+);/';

            if (preg_match($pattern, $code, $matches) !== 1) {
                continue;
            }

            $typePrefixes[$matches[1]] = $prefix;
        }

        return $typePrefixes;
    }

    /**
     * @param  array<int, string>  $codeByLine
     */
    private function callCodeFromLine(array $codeByLine, int $line): string
    {
        $callCode = '';
        $lastLine = array_key_last($codeByLine);

        if ($lastLine === null) {
            return $callCode;
        }

        for ($candidateLine = $line; $candidateLine <= $lastLine; $candidateLine++) {
            if (! array_key_exists($candidateLine, $codeByLine)) {
                continue;
            }

            $code = $codeByLine[$candidateLine];
            $callCode .= $code;

            if (str_contains($code, ';')) {
                break;
            }
        }

        return $callCode;
    }

    /** @return array<int, string> */
    private function codeByLine(string $contents): array
    {
        $codeByLine = [];
        $currentLine = 1;

        foreach (token_get_all($contents) as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            $ignored = in_array(
                $id,
                [
                    T_COMMENT,
                    T_DOC_COMMENT,
                    T_CONSTANT_ENCAPSED_STRING,
                    T_ENCAPSED_AND_WHITESPACE,
                ],
                strict: true,
            );
            $parts = explode("\n", $text);

            foreach ($parts as $offset => $part) {
                if ($ignored || trim($part) === '') {
                    continue;
                }

                $line = $currentLine + $offset;
                $normalizedPart = preg_replace(pattern: '/\s+/', replacement: '', subject: $part);

                if (! is_string($normalizedPart)) {
                    continue;
                }

                $codeByLine[$line] = ($codeByLine[$line] ?? '').$normalizedPart;
            }

            $currentLine += count($parts) - 1;
        }

        ksort($codeByLine);

        return $codeByLine;
    }

    private function hasLaneMarker(string $contents): bool
    {
        return (
            str_contains($contents, '@orbit-ssh-lane provisioning-ssh')
            || str_contains($contents, '@orbit-ssh-lane transitional-ssh')
        );
    }

    private function repositoryRoot(): string
    {
        $root = realpath(base_path('../..'));

        return $root === false ? base_path('../..') : $root;
    }

    private function relativePath(string $path, string $root): string
    {
        $normalizedPath = str_replace(search: '\\', replace: '/', subject: substr($path, strlen($root)));

        return ltrim(string: $normalizedPath, characters: '/');
    }
}
