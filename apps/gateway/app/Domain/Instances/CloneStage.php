<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use LogicException;

/**
 * The release scripts a clone's `clone-container` stages on its Node, framed at admission in the clone's one launch copy: a step's
 * stdin is one whole vault value, and the scripts are larger than one argument (docs/reference/development-containers.md#create).
 *
 * The copy is ONE line of JSON: `stage` (the public scripts, the run's container intent and the SSH account) and `private` (the clone
 * copy's text: the credential line and the model environment). Swamp registers every value a run reads with `vault.get` for
 * redaction, and turns a value with a newline into one regular expression; V8 refuses one built from a line of about 32 KB
 * (release 2080). A single line is matched as a plain string. The launcher stages `stage` and gives the clone script only `private`.
 *
 * Swamp masks a registered value only as a whole, so the bare GitHub token and the bare model key get their own copies, which the
 * create reads so that either one is masked wherever it appears alone.
 */
final readonly class CloneStage
{
    /** The largest launch copy: about five times today's 195 KB, far below what a vault value and a step's stdin carry. */
    public const int MaxBytes = 1_048_576;

    /** The token copy of a repository the Gateway fetches without a token: a word no output needs, which the redactor masks. */
    public const string NoToken = 'orbit-no-github-token';

    public function __construct(private ?string $swamp = null) {}

    /** @return array{vault:string,key:string} */
    public static function reference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-create-launch-'.$operation->id];
    }

    /**
     * The bare GitHub read token of the run, or {@see NoToken}.
     *
     * @return array{vault:string,key:string}
     */
    public static function tokenReference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-create-redact-token-'.$operation->id];
    }

    /**
     * The bare model key of the run.
     *
     * @return array{vault:string,key:string}
     */
    public static function modelReference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-create-redact-model-'.$operation->id];
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
                'mail' => $this->read('ansible/instance_mail.py'),
                'bridge' => $this->read('ansible/instance_bridge.py'),
                'node' => $this->read('ansible/instance_node_runtime.py'),
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
        $value = json_encode(['stage' => $payload, 'private' => $private], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($value) > self::MaxBytes) {
            throw new ResourceOperationException('instance.clone_launch_too_large',
                'The clone launch copy holds '.strlen($value).' bytes, more than the '.self::MaxBytes.' a create may send.', 409);
        }
        if (str_contains($value, "\n")) {
            throw new LogicException('The clone launch copy must be one line.');
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
