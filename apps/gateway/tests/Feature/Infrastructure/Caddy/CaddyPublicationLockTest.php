<?php

declare(strict_types=1);

it('keeps Caddy publication and reads behind agent commands', function (): void {
    foreach (['NodeCaddyBuilder', 'NodeCaddyLiveReader', 'NodeCaddyTransport'] as $class) {
        $source = file_get_contents(app_path("Infrastructure/Caddy/Build/{$class}.php"));
        expect($source)->not->toContain('SshExecutor', 'ProcessRunner', 'RemoteCommand', 'bash');
    }
});
