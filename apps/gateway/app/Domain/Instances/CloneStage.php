<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use LogicException;

/**
 * The release scripts a clone's `clone-container` stages on its Node, framed at admission in the clone's one launch copy: a step's
 * stdin is one whole vault value, and the scripts are larger than one argument (docs/reference/development-containers.md#create).
 * A clone's completion gets the same scripts in its own one copy ({@see completion()}).
 *
 * The copy is ONE line of JSON: `stage` (the public scripts, the run's container intent and the SSH account) and `private` (the clone
 * copy's text: the credential line and the model environment). Swamp registers every value a run reads with `vault.get` for
 * redaction, and turns a value with a newline into one regular expression; V8 refuses one built from a line of about 32 KB
 * (release 2080). A single line is matched as a plain string. The launcher stages `stage` and gives the clone script only `private`.
 *
 * Swamp masks a registered value only as a whole, never a part of it: a token inside this copy is not masked when it appears alone.
 * The clone script's own redaction keeps it out of a failed part's detail (instance_clone.py `private_values`, `public_text`).
 */
final readonly class CloneStage
{
    /** The largest launch copy: about five times today's 195 KB, far below what a vault value and a step's stdin carry. */
    public const int MaxBytes = 1_048_576;

    public function __construct(private ?string $swamp = null) {}

    /** @return array{vault:string,key:string} */
    public static function reference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-create-launch-'.$operation->id];
    }

    /** The public launcher, the program text of the step's `python3 -c`. */
    public function launcher(): string
    {
        return $this->read('scripts/instance_clone_launch.py');
    }

    /**
     * The public part: the launcher, the clone script and its helpers, the run's container intent and the SSH account. A nested-fleet
     * clone gets the nested network program and its Club key script.
     *
     * @param  array<string,mixed>  $container
     * @return array<string,mixed>
     */
    public function payload(array $container, string $account): array
    {
        $network = $this->read('ansible/instance_network.py');
        if (self::present($container, 'allowed_devices')) {
            $network = explode('if __name__ == "__main__":', $network, 2)[0].$this->read('ansible/instance_nested.py');
        }

        return [
            'launcher' => $this->launcher(),
            'script' => $this->read('scripts/instance_clone.py'),
            'container' => $container,
            'guest' => $this->read('scripts/instance_clone_guest.py'),
            'network' => $network,
            'account' => $account,
            'bundle' => [
                'node_services' => $this->read('ansible/instance_node_services.py'),
                'machine_name' => $this->read('ansible/instance_machine_name.py'),
                'root_disk' => $this->read('ansible/instance_root_disk.py'),
                'mail' => $this->read('ansible/instance_mail.py'),
                'bridge' => $this->read('ansible/instance_bridge.py'),
                'node' => $this->read('ansible/instance_node_runtime.py'),
                // Root's workspace pass, which the services part runs as a deployment does (ADR 0268).
                'workspace_owner' => $this->read('ansible/instance_workspace_owner.py'),
                // Only a nested-fleet clone delivers its nested Gateway's Club key.
                ...(self::present($container, 'club_key') ? ['club_key' => $this->read('ansible/instance_nested_club_key.sh')] : []),
            ],
        ];
    }

    /**
     * The launch copy's one line. A copy over {@see MaxBytes} refuses the create by name before its run starts.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function value(array $payload, #[\SensitiveParameter] string $private): string
    {
        return self::line(['stage' => $payload, 'private' => $private], 'instance.clone_launch_too_large', 'clone launch', 'a create');
    }

    /**
     * The reference of a completion's one copy: the key it has always had, so the grants and the workflow's input pattern stay.
     *
     * @return array{vault:string,key:string}
     */
    public static function completionReference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-complete-processes-'.$operation->id];
    }

    /**
     * A completion's one copy (docs/reference/development-containers.md#complete-a-clone), ONE line: the launch copy's `stage` and
     * `private` (the Process files as `{"processes": ...}`, which the services part reads), and the same Process files at the top level
     * as `processes`, for a workflow that stages its scripts from the `input` step and reads the copy's `processes` itself. A copy over
     * {@see MaxBytes} refuses the completion by name before its run starts.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function completion(array $payload, #[\SensitiveParameter] object $processes): string
    {
        $private = json_encode(['processes' => $processes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::line(['stage' => $payload, 'private' => $private, 'processes' => $processes], 'instance.complete_launch_too_large',
            'completion launch', 'a completion');
    }

    /** @param array<string,mixed> $frame */
    private static function line(#[\SensitiveParameter] array $frame, string $code, string $copy, string $sender): string
    {
        $value = json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($value) > self::MaxBytes) {
            throw new ResourceOperationException($code,
                'The '.$copy.' copy holds '.strlen($value).' bytes, more than the '.self::MaxBytes.' '.$sender.' may send.', 409);
        }
        if (str_contains($value, "\n")) {
            throw new LogicException('The '.$copy.' copy must be one line.');
        }

        return $value;
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
