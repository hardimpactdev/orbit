<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\ContainerProcessCommands;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\CurrentRecipe;
use App\Models\Instance;

/**
 * The one rule for host-only paths in a production container release: Process argv, Process environment and the
 * rendered Instance environment. An exact container equivalent replaces the path; any other host-only path refuses
 * at admission, before the Node changes, and names the Process argument or environment key.
 */
final readonly class ContainerHostPaths
{
    public const string Checkout = '/srv/workspace/current';

    /**
     * A host-only path anywhere in a value that does not continue a longer path: at its start, after a separator such
     * as `--runtime=` or `VAR=`, after a shell operator (`;`, `&&`, `||`, `|`, `(`, a backtick, `$(`) or inside quotes.
     * The token runs to the next path terminator, so a shell expansion or glob inside it stays part of the token.
     */
    private const string HostPath = '~(?<![A-Za-z0-9_.\~/])(?:/opt/orbit/|/home/orbit-app-[0-9]+(?![A-Za-z0-9_.-])|/usr/bin/php)[^\s:\'"`,;&|()<>]*~';

    /** A literal path: only these characters may be mapped. Anything else, such as `$`, `*`, `?`, `[` or `\`, refuses. */
    private const string Literal = '~\A[A-Za-z0-9._/+@%=-]+\z~D';

    /**
     * A Node's managed headless Chrome (swamp/ansible/tasks/node-converge-browser.yml): its `releases/<sha256>` folder, or
     * a Chrome version folder that an older host kept. Chrome is not version-pinned like Node: either maps to the runtime folder's one.
     */
    private const string HostChrome = '~\A/opt/orbit/chrome-headless-shell/(?:releases/[0-9a-f]{64}|[0-9]+(?:[.][0-9]+)*)/chrome-headless-shell-linux64/chrome-headless-shell\z~D';

    public function __construct(private ContainerProcessCommands $commands, private CurrentRecipe $recipes) {}

    public function map(string $value, Instance $instance, string $php, string $label): string
    {
        return (string) preg_replace_callback(self::HostPath, fn (array $match): string => $this->path($match[0][0],
            $this->continues($value, $match[0][1], $match[0][1] + strlen($match[0][0])), $instance, $php, $label), $value, flags: PREG_OFFSET_CAPTURE);
    }

    /**
     * Whether the shell word goes on past the path, as in `/usr/bin/php`$V`` or `"/usr/bin/php"8.5`. A quote or backtick
     * after the path must close the one before it and end the word; an opening parenthesis never ends a path.
     */
    private function continues(string $value, int $start, int $end): bool
    {
        $next = $value[$end] ?? '';
        if ($next === '(') {
            return true;
        }
        if (! in_array($next, ['"', "'", '`'], true)) {
            return false;
        }

        return ($start > 0 ? $value[$start - 1] : '') !== $next || preg_match('~\A(?:[\s;&|()<>"\'`,:]|\z)~', substr($value, $end + 1)) !== 1;
    }

    private function path(string $path, bool $continues, Instance $instance, string $php, string $label): string
    {
        if ($continues || preg_match(self::Literal, $path) !== 1) {
            throw new ResourceOperationException('deployment.host_path_unsupported',
                "{$label} names the host-only path [{$path}], which uses shell expansion or a glob that Orbit cannot map to a container path. Write the literal container path or a command on PATH before deploying.", 409);
        }
        if (preg_match('~\A/usr/bin/php(?:[0-9]+[.][0-9]+)?\z~D', $path) === 1) {
            return $this->commands->resolve([$path], $php, $label, hostPaths: true)[0];
        }
        if (preg_match('~\A/opt/orbit/vite-plus/(?:bin/(?<tool>[a-z][a-z0-9-]*)|js_runtime/node/(?<version>[^/]+)/bin/(?<node>node|npm|npx))\z~D', $path, $match) === 1) {
            $name = ($match['node'] ?? '') !== '' ? $match['node'] : $match['tool'];
            if ($name !== 'chrome-headless-shell' && $this->commands->supportsName($name)) {
                $runtime = $this->commands->version($name);
                if (($match['node'] ?? '') !== '' && $match['version'] !== $runtime) {
                    throw new ResourceOperationException('deployment.host_path_unsupported',
                        "{$label} names the host-only path [{$path}], which runs Node [{$match['version']}]. The container runtime ships Node [{$runtime}] at [".InstanceHostIntent::RuntimeFolder."/current/bin/{$name}]. Change it to `{$name}` on PATH or that path before deploying.", 409);
                }

                return InstanceHostIntent::RuntimeFolder.'/current/bin/'.$name;
            }
        }
        $chrome = $this->recipes->currentRecipe()->instances->runtime['chrome-headless-shell'] ?? null;
        if ($chrome !== null && preg_match(self::HostChrome, $path) === 1) {
            return InstanceHostIntent::RuntimeFolder.'/current/chrome-headless-shell/'.$chrome->bin['chrome-headless-shell'];
        }
        $home = '/home/orbit-app-'.$instance->project_id;
        foreach (array_filter([$instance->checkout_path, $home.'/current']) as $checkout) {
            if ($path === $checkout || str_starts_with($path, rtrim($checkout, '/').'/')) {
                return self::Checkout.substr($path, strlen(rtrim($checkout, '/')));
            }
        }

        throw new ResourceOperationException('deployment.host_path_unsupported',
            "{$label} names the host-only path [{$path}], which a production container does not have. Change it to a container path or a command on PATH before deploying.", 409);
    }
}
