<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\ContainerProcessCommands;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

/**
 * The one rule for host-only paths in a production container release: Process argv, Process environment and the
 * rendered Instance environment. An exact container equivalent replaces the path; any other host-only path refuses
 * at admission, before the Node changes, and names the Process argument or environment key.
 */
final readonly class ContainerHostPaths
{
    public const string Checkout = '/srv/workspace/current';

    /** A host-only path starts a value or follows a separator, as in `--runtime=/opt/orbit/...` or `A:/usr/bin/php8.5`. */
    private const string HostPath = '~(?<=^|[\s=:\'",])(?:/opt/orbit/|/home/orbit-app-[0-9]+(?=/|$)|/usr/bin/php)[^\s:\'",;]*~';

    public function __construct(private ContainerProcessCommands $commands) {}

    public function map(string $value, Instance $instance, string $php, string $label): string
    {
        return (string) preg_replace_callback(self::HostPath, fn (array $match): string => $this->path($match[0], $instance, $php, $label), $value);
    }

    private function path(string $path, Instance $instance, string $php, string $label): string
    {
        if (preg_match('~\A/usr/bin/php(?:[0-9]+[.][0-9]+)?\z~D', $path) === 1) {
            return $this->commands->resolve([$path], $php, $label, hostPaths: true)[0];
        }
        if (preg_match('~\A/opt/orbit/vite-plus/(?:bin/(?<tool>[a-z][a-z0-9-]*)|js_runtime/node/[0-9][0-9.]*/bin/(?<node>node|npm|npx))\z~D', $path, $match) === 1) {
            $name = ($match['node'] ?? '') !== '' ? $match['node'] : $match['tool'];
            if ($this->commands->supportsName($name)) {
                return InstanceHostIntent::RuntimeFolder.'/current/bin/'.$name;
            }
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
