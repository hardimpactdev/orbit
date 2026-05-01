<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as BaseKernel;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Kernel extends BaseKernel
{
    #[\Override]
    public function bootstrap()
    {
        $this->addCommandPaths([
            $this->app->basePath('app/Console/Commands'),
        ]);

        parent::bootstrap();
    }

    #[\Override]
    protected function shouldDiscoverCommands()
    {
        return true;
    }

    #[\Override]
    protected function getArtisan()
    {
        if (is_null($this->artisan)) {
            $this->artisan = new Application($this->app, $this->events, $this->app->version())
                ->resolveCommands($this->commands)
                ->setContainerCommandLoader();

            if ($this->symfonyDispatcher instanceof EventDispatcher) {
                $this->artisan->setDispatcher($this->symfonyDispatcher);
                $this->artisan->setSignalsToDispatchEvent();
            }
        }

        $this->artisan->setName((string) config('app.name', 'Orbit'));
        $this->artisan->setVersion((string) config('app.version', '0.0.0'));

        foreach ($this->artisan->all() as $command) {
            $this->hideNonOrbitCommand($command);
        }

        return $this->artisan;
    }

    private function hideNonOrbitCommand(SymfonyCommand $command): void
    {
        if (str_starts_with($command::class, 'App\\Console\\Commands\\')) {
            return;
        }

        $command->setHidden(true);
    }
}
