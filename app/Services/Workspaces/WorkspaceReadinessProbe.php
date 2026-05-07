<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;

final readonly class WorkspaceReadinessProbe
{
    public function probe(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.node']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            return ['reachable' => false, 'status' => 'no_app'];
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            return ['reachable' => false, 'status' => 'no_node'];
        }

        $url = $workspace->url();

        $ch = curl_init($url);

        if ($ch === false) {
            return ['reachable' => false, 'status' => 'curl_init_failed'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_NOBODY, true);

        curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return ['reachable' => false, 'status' => 'error: '.$error];
        }

        if ($statusCode >= 500) {
            return ['reachable' => false, 'status' => (string) $statusCode];
        }

        return ['reachable' => true, 'status' => (string) $statusCode];
    }
}
