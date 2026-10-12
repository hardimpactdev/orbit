<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologyImages;

use App\Console\Commands\E2ECommand;
use App\E2E\Interrupted;
use App\E2E\RoleImageBuilder;
use App\E2E\Value\RoleImage;
use InvalidArgumentException;
use Throwable;

final class BuildCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology-images:build'
        .' {--role=* : A role image to build: gateway, app-dev, app-prod, or operator; default all four}'
        .' {--json}';

    #[\Override]
    protected $description = 'Build identity-free role images for composed topologies';

    public function handle(RoleImageBuilder $builder): int
    {
        try {
            // A signal unwinds the work like a failure, so the build or acquisition removes what it created.
            $this->trap([SIGINT, SIGTERM, SIGHUP], static function (int $signal): never {
                throw Interrupted::bySignal($signal);
            });
            $images = $this->images();
            $timings = [];
            $started = microtime(true);
            $built = $builder->build($images, function (string $stage, float $seconds) use (&$timings): void {
                $timings[$stage] = round($seconds, 1);
                if (! $this->option('json')) {
                    $this->getOutput()->getErrorStyle()->writeln(sprintf('%-24s %7.1f s', $stage, $seconds));
                }
            });
            $timings['total'] = round(microtime(true) - $started, 1);
            $text = [];
            foreach ($built as $role => $fingerprint) {
                $text[] = RoleImage::from($role)->alias().' '.$fingerprint;
            }
            $this->outputJson(['state' => 'built', 'images' => $built, 'timings' => $timings], implode("\n", $text));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    /** @return list<RoleImage> */
    private function images(): array
    {
        $roles = $this->option('role');
        if ($roles === []) {
            return RoleImage::cases();
        }
        $images = [];
        foreach ($roles as $role) {
            $image = is_string($role) ? RoleImage::tryFrom($role) : null;
            if ($image === null) {
                throw new InvalidArgumentException('A role image must be gateway, app-dev, app-prod, or operator.');
            }
            $images[$image->value] = $image;
        }

        return array_values($images);
    }
}
