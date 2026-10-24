<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;

/**
 * What a host command `sudo -n incus --project <project> exec …` runs in the guest: the VM, the user, the working
 * directory, the argv, and stdin. Tests assert on these instead of on the position of each `incus exec` option.
 */
final readonly class IncusGuestCall
{
    /** @param  list<string>  $argv */
    private function __construct(
        public string $vm,
        public string $user,
        public ?string $cwd,
        public array $argv,
        public string|ProtectedInput|null $input,
    ) {}

    public static function decode(RemoteCommand $command): ?self
    {
        $operation = array_slice($command->arguments, 5);
        if (($operation[0] ?? null) !== 'exec') {
            return null;
        }
        $separator = (int) array_search('--', $operation, true);
        $options = array_slice($operation, 1, $separator - 1);
        $cwd = null;
        foreach ($options as $index => $option) {
            if ($option === '--cwd') {
                $cwd = $options[$index + 1];
            }
        }
        $vm = $operation[$separator + 1];
        $argv = array_slice($operation, $separator + 2);
        $user = 'root';
        if (($argv[0] ?? null) === 'runuser' && $argv[1] === '-u' && $argv[3] === '--') {
            $user = $argv[2];
            $argv = array_slice($argv, 4);
        }

        return new self($vm, $user, $cwd, array_values($argv), $command->protectedInput ?? $command->input);
    }
}
