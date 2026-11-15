<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Operation;
use LogicException;

/**
 * The release scripts a clone's `clone-container` stages on its Node, framed at admission in the clone's one stage copy: a step's
 * stdin is one whole vault value, and the scripts are larger than one argument (docs/reference/development-containers.md#create).
 * The copy is one line of public JSON, then the private text of the clone copy (InstanceGitHubReadTokens::cloneReference). The
 * launcher writes the JSON into the clone's private folder and gives the clone script only the private text.
 */
final readonly class CloneStage
{
    public function __construct(private ?string $swamp = null) {}

    /** @return array{vault:string,key:string} */
    public static function reference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-create-stage-'.$operation->id];
    }

    /** The public launcher, the program text of the step's `python3 -c`. */
    public function launcher(): string
    {
        return $this->read('scripts/instance_clone_launch.py');
    }

    /**
     * The public JSON line: the launcher, the clone script and its helpers, the run's container intent and the SSH account. A nested-fleet
     * clone gets the nested network program and its Club key script.
     *
     * @param  array<string,mixed>  $container
     */
    public function payload(array $container, string $account): string
    {
        $network = $this->read('ansible/instance_network.py');
        if (self::present($container, 'allowed_devices')) {
            $network = explode('if __name__ == "__main__":', $network, 2)[0].$this->read('ansible/instance_nested.py');
        }

        return json_encode([
            'launcher' => $this->launcher(),
            'script' => $this->read('scripts/instance_clone.py'),
            'container' => $container,
            'guest' => $this->read('scripts/instance_clone_guest.py'),
            'network' => $network,
            'account' => $account,
            'bundle' => [
                'node_services' => $this->read('ansible/instance_node_services.py'),
                'machine_name' => $this->read('ansible/instance_machine_name.py'),
                'mail' => $this->read('ansible/instance_mail.py'),
                'bridge' => $this->read('ansible/instance_bridge.py'),
                'node' => $this->read('ansible/instance_node_runtime.py'),
                // Only a nested-fleet clone delivers its nested Gateway's Club key.
                ...(self::present($container, 'club_key') ? ['club_key' => $this->read('ansible/instance_nested_club_key.sh')] : []),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $container */
    private static function present(array $container, string $key): bool
    {
        $value = $container[$key] ?? null;

        return $value !== null && $value !== false && $value !== '' && $value !== 0;
    }

    private function read(string $path): string
    {
        $file = ($this->swamp ?? base_path('../../swamp')).'/'.$path;
        $content = is_file($file) ? file_get_contents($file) : false;
        if ($content === false) {
            throw new LogicException("The clone stage file [{$path}] is missing.");
        }

        return $content;
    }
}
