<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Models\App;
use RuntimeException;

final readonly class EnactAppRuntime
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing('node');

        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $result = $this->remoteShell->run($app->node, sprintf(
            'command -v %1$s >/dev/null 2>&1 || command -v php-fpm >/dev/null 2>&1',
            escapeshellarg("php{$app->php_version}-fpm"),
        ));

        if ($result->successful()) {
            return [];
        }

        return [[
            'code' => 'app.php_version_unavailable',
            'family' => 'app',
            'message' => "PHP {$app->php_version} FPM is not available on '{$app->node->name}'. Run doctor to converge app runtime artifacts.",
            'next_command' => 'doctor --family=app --fix',
        ]];
    }
}
