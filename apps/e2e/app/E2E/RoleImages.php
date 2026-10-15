<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\RoleImage;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/** Reads the imported recipe base used by every topology Node. */
final readonly class RoleImages
{
    public function __construct(private IncusHost $host, private int $settleIntervalMicroseconds = 1_000_000) {}

    /**
     * @param  list<RoleImage>  $images
     * @return array<string, string>
     */
    public function resolve(array $images): array
    {
        $base = $this->base();
        if ($base === null) {
            throw new RuntimeException('The recipe base image is missing or invalid; import it with bin/e2e-topology-images ensure --recipe-images=PATH. Pre-recipe groups must drain or be cancelled.');
        }

        return array_fill_keys(array_map(static fn (RoleImage $role): string => $role->value, $images), $base['fingerprint']);
    }

    /** @return array{fingerprint:string,type:string,aliases:list<string>,properties:array<string,string>,created_at:string}|null */
    private function base(): ?array
    {
        $matches = array_values(array_filter($this->host->images(), static fn (array $image): bool => in_array(RoleImage::Gateway->alias(), $image['aliases'], true) && $image['type'] === 'virtual-machine'
            && ($image['properties']['orbit.profile'] ?? null) === 'base'
            && preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image['properties']['orbit.recipe'] ?? '') === 1));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** Recipe images retain cloud-init; wait through its seed reboot before configuring a topology. */
    public function settle(TopologyTarget $target): void
    {
        $guests = array_values($target->instances());
        $probe = 'case "$(cloud-init status 2>/dev/null)" in *done*|*disabled*) ;; *) exit 1 ;; esac; cat /proc/sys/kernel/random/boot_id';
        $previous = null;
        $deadline = microtime(true) + 900;
        do {
            $commands = [];
            foreach ($guests as $guest) {
                $commands[$guest] = ['instance' => $guest, 'command' => new GuestCommand(['sh', '-c', $probe], 15)];
            }
            try {
                $boots = array_map(static fn (GuestCommandResult $result): ?string => $result->successful() && trim($result->stdout) !== '' ? trim($result->stdout) : null, $this->host->execAll($commands));
            } catch (RuntimeException) {
                $boots = [];
            }
            if ($boots !== [] && ! in_array(null, $boots, true) && $boots === $previous) {
                return;
            }
            $previous = $boots;
            usleep($this->settleIntervalMicroseconds);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The imported recipe guests did not finish their first boot.');
    }

    /** @return list<array{role:string,alias:string,state:string,fingerprint:?string,created_at:?string,recipe_identity:?string,error:?string}> */
    public function status(): array
    {
        $base = $this->base();

        return [['role' => 'base', 'alias' => RoleImage::Gateway->alias(), 'state' => $base === null ? 'missing' : 'current',
            'fingerprint' => $base['fingerprint'] ?? null, 'created_at' => $base['created_at'] ?? null,
            'recipe_identity' => $base['properties']['orbit.recipe'] ?? null, 'error' => null]];
    }
}
