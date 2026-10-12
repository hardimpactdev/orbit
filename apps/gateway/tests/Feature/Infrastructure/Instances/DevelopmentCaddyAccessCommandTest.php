<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Instances\DevelopmentCaddyAccessCommand;
use App\Infrastructure\Ssh\RemoteCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LinuxHost;

pest()->group('privileged');

it('leaves out sites that have no checkout to grant access to', function (): void {
    $site = static fn (string $checkoutPath, ?string $localHttpUpstream = null): DevelopmentSite => new DevelopmentSite(
        nodeId: 1,
        nodeAddress: '10.44.0.7',
        scope: 'node-1',
        checkoutPath: $checkoutPath,
        documentRoot: 'public',
        phpVersion: '8.5',
        domain: 'example.test',
        localHttpUpstream: $localHttpUpstream,
    );

    $command = new DevelopmentCaddyAccessCommand()->command(collect([
        $site('/apps/served'),
        $site(''),
        $site('/apps/proxied', 'http://127.0.0.1:4788'),
    ]));

    expect($site('/apps/proxied', 'http://127.0.0.1:4788')->isProxy())->toBeTrue()
        ->and($command->arguments)->toContain('/apps/served')
        ->and($command->arguments)->not->toContain('/apps/proxied', '');
});

it('serves a nested Web root while protecting source and preserving shared parent modes', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $homeMode = fileperms($root) & 0o777;
        $sourceMode = fileperms("$root/checkout/.env") & 0o777;
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));
        $process = new Process($command->arguments)->setInput($command->input);
        $process->mustRun();
        $process->mustRun();

        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/index.html"])->mustRun()->getOutput())
            ->toBe('first page');
        foreach (['.env', 'source.txt', '.git/config', 'web/private.txt'] as $private) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/$private"])->run())
                ->toBe(1);
        }
        file_put_contents("$root/checkout/web/site/next.html", 'next page');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/next.html"])->mustRun()->getOutput())
            ->toBe('next page');
        clearstatcache();
        expect(fileperms($root) & 0o007)->toBe($homeMode & 0o007);
        expect(fileperms("$root/checkout/.env") & 0o777)->toBe($sourceMode);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('grants a default release access without exposing shared Git metadata or the environment', function (bool $selected): void {
    if (LinuxHost::delegate($this)) {
        return;
    }
    $root = development_caddy_access_fixture();
    try {
        $home = "$root/checkout";
        new Process(['git', '-C', $home, 'add', '.'])->mustRun();
        new Process(['git', '-C', $home, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'initial'])->mustRun();
        new Process(['git', '-C', $home, 'worktree', 'add', '--detach', "$home/releases/initial", 'HEAD'])->mustRun();
        mkdir("$home/.git/orbit-development-releases", 0700);
        file_put_contents("$home/.git/orbit-development-releases/identity", 'fixture');
        file_put_contents("$home/.git/orbit-development-releases/release-initial", 'fixture:initial');
        symlink('releases/initial', "$home/current");
        $checkout = $selected ? "$home/current" : "$home/releases/initial";
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]));
        $gitMode = fileperms("$home/.git") & 0o777;
        new Process($command->arguments)->setInput($command->input)->mustRun();
        new Process($command->arguments)->setInput($command->input)->mustRun();

        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$home/current/web/site/index.html"])->mustRun()->getOutput())->toBe('first page');
        foreach (["$home/.git/config", "$home/.git/worktrees/initial/HEAD", "$home/current/.env", "$home/current/source.txt"] as $private) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', $private])->run())->not->toBe(0);
        }
        clearstatcache();
        expect(fileperms("$home/.git") & 0o777)->toBe($gitMode);
        // Denying Caddy traversal must not deny the Node user its shared repository.
        expect(new Process(['git', '-C', "$home/current", 'rev-parse', '--git-common-dir'])->mustRun()->getOutput())->not->toBe('');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with(['selected current' => true, 'candidate before activation' => false]);

it('refuses unsafe Web root links before changing file access', function (string $kind): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        mkdir("$root/outside");
        file_put_contents("$root/outside/private", 'outside');
        if ($kind === 'root') {
            new Filesystem()->deleteDirectory("$root/checkout/web/site");
            symlink("$root/outside", "$root/checkout/web/site");
        } else {
            symlink("$root/outside/private", "$root/checkout/web/site/leak");
        }
        $before = new Process(['getfacl', '-cp', "$root/checkout", "$root/outside"])->mustRun()->getOutput();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));

        expect(new Process($command->arguments)->setInput($command->input)->run())->not->toBe(0);
        expect(new Process(['getfacl', '-cp', "$root/checkout", "$root/outside"])->mustRun()->getOutput())->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with(['root', 'descendant']);

it('permits only Laravel public storage without exposing private storage', function (string $relative): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $application = "$root/checkout".$relative;
        mkdir("$application/public", 0o755, true);
        mkdir("$application/storage/app", 0o700, true);
        mkdir("$application/storage/app/public", 0o755);
        file_put_contents("$application/storage/app/public/photo.txt", 'public photo');
        file_put_contents("$application/storage/app/private.txt", 'private photo');
        symlink('../storage/app/public', "$application/public/storage");
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", ltrim($relative.'/public', '/'))]));
        new Process($command->arguments)->setInput($command->input)->mustRun();

        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$application/public/storage/photo.txt"])->mustRun()->getOutput())
            ->toBe('public photo');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$application/storage/app/private.txt"])->run())
            ->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with(['root public' => '', 'nested Laravel' => '/server/web']);

it('keeps both Web roots readable when a Git worktree is nested inside another checkout', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $nested = "$root/checkout/.worktrees/feature";
        mkdir("$nested/public", 0o700, true);
        chmod("$nested/public", 0o755);
        new Process(['git', 'init', '--quiet', $nested])->mustRun();
        file_put_contents("$nested/public/index.html", 'nested page');
        file_put_contents("$nested/.env", 'nested secret');
        $command = new DevelopmentCaddyAccessCommand()->command(collect([
            development_caddy_access_site($nested, 'public'),
            development_caddy_access_site("$root/checkout", 'web/site'),
        ]));
        new Process($command->arguments)->setInput($command->input)->mustRun();

        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$nested/public/index.html"])->mustRun()->getOutput())
            ->toBe('nested page');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/index.html"])->mustRun()->getOutput())
            ->toBe('first page');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$nested/.env"])->run())->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('restores what it grants and keeps the source denied, or retains its snapshot when recovery also fails', function (bool $failRecovery, bool $linked): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $checkout = "$root/checkout";
        if ($linked) {
            new Process(['git', '-C', $checkout, 'add', '.'])->mustRun();
            new Process(['git', '-C', $checkout, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-m', 'initial'])->mustRun();
            $checkout = "$root/checkout/releases/initial";
            new Process(['git', '-C', "$root/checkout", 'worktree', 'add', '--detach', $checkout, 'HEAD'])->mustRun();
        }
        // Names with a backslash, which a text snapshot would have to quote.
        file_put_contents("$checkout/web/site/x\\057y", 'escaped slash');
        file_put_contents("$checkout/web/site/back\\\\slash", 'backslashes');
        mkdir("$root/bin");
        file_put_contents("$root/bin/setfacl", <<<'BASH'
            #!/bin/bash
            for argument in "$@"; do
                if [ "$argument" = 'u:caddy:r-X' ]; then exit 1; fi
            done
            exec /usr/bin/setfacl "$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        if ($failRecovery) {
            file_put_contents("$root/bin/python3", <<<'BASH'
                #!/bin/bash
                for argument in "$@"; do
                    if [ "$argument" = restore ]; then exit 1; fi
                done
                exec /usr/bin/python3 "$@"
                BASH);
            chmod("$root/bin/python3", 0o700);
        }
        // The walk grants the Web root, its ancestors, and the shared Git directory; it denies the rest.
        $ancestors = [];
        for ($ancestor = "$checkout/web"; $ancestor !== dirname($root); $ancestor = dirname($ancestor)) {
            $ancestors[] = $ancestor;
        }
        $granted = static fn (): string => new Process(['getfacl', '-R', '-p', "$checkout/web/site"])->mustRun()->getOutput()
            .new Process(['getfacl', '-p', ...$ancestors, "$root/checkout/.git"])->mustRun()->getOutput();
        $before = $granted();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([
            development_caddy_access_site($checkout, 'web/site'),
        ]));

        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        expect($process->setInput($command->input)->run())->not->toBe(0);
        // Nothing lands in the Git directory, which the task worker can write.
        expect(new Filesystem()->glob("$root/checkout/.git/orbit-caddy-acl.*"))->toBe([]);
        $retained = development_caddy_access_retained($process);
        if ($failRecovery) {
            expect($retained)->toStartWith('/var/tmp/orbit-caddy-acl.')
                ->and(fileowner($retained))->toBe(posix_geteuid())
                ->and(fileperms($retained) & 0o777)->toBe(0o700);
            // The test account owns every saved entry, so its own restore puts them back.
            expect(fileperms("$retained/entries/".posix_geteuid()) & 0o777)->toBe(0o600)
                ->and(file_exists("$retained/ancestors/0"))->toBeFalse();
            new Process(['python3', '-I', '-c', development_caddy_access_helper($command), 'restore'])
                ->setInput(file_get_contents("$retained/entries/".posix_geteuid()))->mustRun();
            new Filesystem()->deleteDirectory($retained);
        } else {
            expect($retained)->toBeNull();
        }
        expect($granted())->toBe($before);
        foreach (['.env', 'source.txt', 'web/private.txt'] as $private) {
            expect(new Process(['getfacl', '-cp', "$checkout/$private"])->mustRun()->getOutput())->toContain('user:caddy:---');
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'checkout recovery' => [false, false],
    'checkout failed recovery' => [true, false],
    'shared Git recovery' => [false, true],
    'shared Git failed recovery' => [true, true],
]);

it('finishes its walk while setup removes and replaces files under the checkout and the Web root', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();
    $churn = null;

    try {
        $checkout = "$root/checkout";
        foreach (range(1, 40) as $directory) {
            mkdir("$checkout/web/site/build/d$directory", 0o755, true);
            mkdir("$checkout/vendor/p$directory", 0o700, true);
            foreach (range(1, 20) as $file) {
                file_put_contents("$checkout/web/site/build/d$directory/f$file.js", 'asset');
                file_put_contents("$checkout/vendor/p$directory/f$file.php", 'source');
            }
        }
        // Like `vp build` and Composer during the baseline setup: directories and files vanish and come back.
        $churn = new Process(['bash', '-c', <<<'BASH'
            while :; do
                for directory in "$1"/web/site/build/d* "$1"/vendor/p*; do
                    rm -rf -- "$directory"
                    mkdir -p -- "$directory"
                    for file in 1 2 3 4 5; do printf x > "$directory/f$file.js"; done
                done
            done
            BASH, 'churn', $checkout]);
        $churn->start();
        usleep(200_000);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]));
        $failures = [];
        foreach (range(1, 10) as $run) {
            $process = new Process($command->arguments)->setInput($command->input);
            if ($process->run() !== 0) {
                $failures[] = $process->getErrorOutput();
            }
        }
        $churn->stop(0);

        expect($failures)->toBe([]);
        // A build after the walk creates its output with the usual modes; the default ACLs make it readable.
        mkdir("$checkout/web/site/build/late", 0o755, true);
        file_put_contents("$checkout/web/site/build/late/app.js", 'late asset');
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/build/late/app.js"])->mustRun()->getOutput())->toBe('late asset')
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/index.html"])->mustRun()->getOutput())->toBe('first page');
        foreach (['.env', 'source.txt', 'web/private.txt'] as $private) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/$private"])->run())->toBe(1);
        }
        mkdir("$checkout/vendor/late", 0o700, true);
        file_put_contents("$checkout/vendor/late/new.php", 'new source');
        expect(new Process(['getfacl', '-cp', "$checkout/vendor/late/new.php"])->mustRun()->getOutput())->toContain('user:caddy:---');
    } finally {
        $churn?->stop(0);
        new Filesystem()->deleteDirectory($root);
    }
});

it('treats only a vanished entry as harmless, and fails on any other walk error', function (string $error, bool $succeeds): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        mkdir("$root/bin");
        // The Web root grant applies its change and then reports one entry, as a walk that races setup does.
        file_put_contents("$root/bin/setfacl", <<<BASH
            #!/bin/bash
            /usr/bin/setfacl "\$@" || exit
            for argument in "\$@"; do
                if [ "\$argument" = u:caddy:r-X ]; then printf 'setfacl: %s/web/site/gone.js: {$error}\\n' "$root/checkout" >&2; exit 1; fi
            done
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));
        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        $process->setInput($command->input)->run();

        expect($process->getExitCode() === 0)->toBe($succeeds, $process->getErrorOutput());
        if ($succeeds) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/index.html"])->mustRun()->getOutput())->toBe('first page')
                ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/source.txt"])->run())->toBe(1);
        } else {
            expect($process->getErrorOutput())->toContain($error);
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'a vanished entry' => ['No such file or directory', true],
    'a refused entry' => ['Operation not permitted', false],
]);

it('changes each entry as its owner on a checkout shared with the task worker', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $checkout = "$root/checkout";
        mkdir("$checkout/web/site/assets");
        file_put_contents("$checkout/web/site/assets/app.js", 'worker asset');
        file_put_contents("$checkout/web/site/worker.html", 'worker page');
        file_put_contents("$checkout/worker-source.txt", 'worker source');
        // The worker's Git wrote these files; it owns them, and only it may change their ACL.
        development_caddy_access_share($root);
        new Process(['sudo', '-n', 'chown', 'nobody', "$checkout/web/site/assets", "$checkout/web/site/assets/app.js", "$checkout/web/site/worker.html", "$checkout/worker-source.txt"])->mustRun();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]), 'nobody');

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->toBe(0, $process->getErrorOutput());
        foreach (['web/site/index.html' => 'first page', 'web/site/worker.html' => 'worker page', 'web/site/assets/app.js' => 'worker asset'] as $served => $content) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/$served"])->mustRun()->getOutput())->toBe($content);
        }
        foreach (['.env', 'source.txt', 'worker-source.txt'] as $private) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/$private"])->run())->toBe(1);
        }
        expect(fileowner("$checkout/web/site/worker.html"))->toBe(posix_getpwnam('nobody')['uid']);

        // Without the worker, or for an entry neither account owns, the walk stops instead of skipping it.
        $managedOnly = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]));
        $process = new Process($managedOnly->arguments)->setInput($managedOnly->input);
        expect($process->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('neither');
        file_put_contents("$checkout/foreign.txt", 'foreign');
        new Process(['sudo', '-n', 'chown', 'root', "$checkout/foreign.txt"])->mustRun();
        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain("$checkout/foreign.txt");
    } finally {
        new Process(['sudo', '-n', 'rm', '-rf', '--', $root])->run();
    }
});

it('never follows a symlink that the worker swaps in at a saved entry when it restores', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();
    $retained = null;

    try {
        $checkout = "$root/checkout";
        file_put_contents("$checkout/web/site/worker.html", 'worker page');
        file_put_contents("$root/target", 'root file');
        development_caddy_access_share($root);
        new Process(['sudo', '-n', 'chown', 'nobody', "$checkout/web/site/worker.html"])->mustRun();
        new Process(['sudo', '-n', 'chown', 'root:root', "$root/target"])->mustRun();
        new Process(['sudo', '-n', 'chmod', '0600', "$root/target"])->mustRun();
        $before = new Process(['getfacl', '-p', "$root/target"])->mustRun()->getOutput();
        // After the snapshot saved worker.html as the worker's, the worker swaps a symlink to a root file in and
        // fails the walk, so recovery restores the saved entry at that name.
        mkdir("$root/bin");
        file_put_contents("$root/bin/setfacl", <<<BASH
            #!/bin/bash
            for argument in "\$@"; do
                if [ "\$argument" = u:caddy:r-X ]; then
                    sudo -n -u nobody rm -f -- '$checkout/web/site/worker.html'
                    sudo -n -u nobody ln -s -- '$root/target' '$checkout/web/site/worker.html'
                    exit 1
                fi
            done
            exec /usr/bin/setfacl "\$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]), 'nobody');

        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        expect($process->setInput($command->input)->run())->not->toBe(0);
        $retained = development_caddy_access_retained($process);
        clearstatcache();
        // The worker's restore names the replaced entry and fails, so the snapshot stays.
        expect(is_link("$checkout/web/site/worker.html"))->toBeTrue()
            ->and(fileowner("$root/target"))->toBe(0)
            ->and(new Process(['getfacl', '-p', "$root/target"])->mustRun()->getOutput())->toBe($before)
            ->and($process->getErrorOutput())->toContain("$checkout/web/site/worker.html: Replaced since the snapshot")
            ->and($retained)->toStartWith('/var/tmp/orbit-caddy-acl.');
    } finally {
        if ($retained !== null) {
            new Filesystem()->deleteDirectory($retained);
        }
        new Process(['sudo', '-n', 'rm', '-rf', '--', $root])->run();
    }
});

it('never follows a symlink that the worker swaps in at a directory above the Web root', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();
    $retained = null;

    try {
        $checkout = "$root/checkout";
        development_caddy_access_share($root);
        new Process(['sudo', '-n', 'chown', 'nobody', "$checkout/web"])->mustRun();
        new Process(['sudo', '-n', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0700', "$root/private"])->mustRun();
        $before = new Process(['getfacl', '-p', "$root/private"])->mustRun()->getOutput();
        // After the Web root passed its checks, the worker moves its directory above the Web root away and puts a
        // symlink to a root directory Caddy cannot pass in its place, so the passage grant meets the symlink.
        mkdir("$root/bin");
        file_put_contents("$root/bin/setfacl", <<<BASH
            #!/bin/bash
            for argument in "\$@"; do
                if [ "\$argument" = u:caddy:--- ] && [ ! -e '$root/swapped' ]; then
                    touch '$root/swapped'
                    sudo -n -u nobody mv -- '$checkout/web' '$checkout/moved'
                    sudo -n -u nobody ln -s -- '$root/private' '$checkout/web'
                fi
            done
            exec /usr/bin/setfacl "\$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]), 'nobody');

        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        expect($process->setInput($command->input)->run())->not->toBe(0);
        $retained = development_caddy_access_retained($process);
        clearstatcache();
        expect(file_exists("$root/swapped"))->toBeTrue($process->getErrorOutput())
            ->and(fileowner("$root/private"))->toBe(0)
            ->and(new Process(['getfacl', '-p', "$root/private"])->mustRun()->getOutput())->toBe($before)
            ->and($process->getErrorOutput())->toContain("$checkout/web: Not a directory");
    } finally {
        if ($retained !== null) {
            new Filesystem()->deleteDirectory($retained);
        }
        new Process(['sudo', '-n', 'rm', '-rf', '--', $root])->run();
    }
});

it('restores the entries of both accounts and the root directory above the checkout after a failed walk', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    // Caddy cannot pass this root directory above the checkout, so root grants the passage.
    $base = '/var/tmp/orbit-caddy-access-'.Str::uuid();
    new Process(['sudo', '-n', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0700', $base])->mustRun();

    try {
        new Process(['sudo', '-n', 'setfacl', '-m', 'u:'.posix_geteuid().':rwx,u:nobody:--x', $base])->mustRun();
        $root = development_caddy_access_fixture($base);
        $checkout = "$root/checkout";
        mkdir("$checkout/web/site/assets");
        file_put_contents("$checkout/web/site/assets/app.js", 'worker asset');
        file_put_contents("$checkout/web/site/worker.html", 'worker page');
        development_caddy_access_share($root);
        new Process(['sudo', '-n', 'chown', 'nobody', "$checkout/web/site/assets", "$checkout/web/site/assets/app.js", "$checkout/web/site/worker.html"])->mustRun();
        $granted = static fn (): string => new Process(['getfacl', '-R', '-p', "$checkout/web/site"])->mustRun()->getOutput()
            .new Process(['getfacl', '-p', "$checkout/web", $checkout, $root, $base, "$checkout/.git"])->mustRun()->getOutput();
        $before = $granted();
        mkdir("$root/bin");
        file_put_contents("$root/bin/setfacl", <<<'BASH'
            #!/bin/bash
            for argument in "$@"; do
                if [ "$argument" = u:caddy:r-X ]; then exit 1; fi
            done
            exec /usr/bin/setfacl "$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]), 'nobody');

        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        expect($process->setInput($command->input)->run())->not->toBe(0)
            ->and(development_caddy_access_retained($process))->toBeNull($process->getErrorOutput())
            ->and($granted())->toBe($before);

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->toBe(0, $process->getErrorOutput());
        foreach (['web/site/index.html' => 'first page', 'web/site/worker.html' => 'worker page', 'web/site/assets/app.js' => 'worker asset'] as $served => $content) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/$served"])->mustRun()->getOutput())->toBe($content);
        }
        expect(new Process(['getfacl', '-cp', $base])->mustRun()->getOutput())->toContain('user:caddy:--x')
            ->and(fileowner($base))->toBe(0)
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/source.txt"])->run())->toBe(1);
    } finally {
        new Process(['sudo', '-n', 'rm', '-rf', '--', $base])->run();
    }
});

it('refuses to act as root below a directory that root does not own', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        // The helper of the walk, run as root on a directory of the managed user.
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));
        $before = new Process(['getfacl', '-p', "$root/checkout"])->mustRun()->getOutput();
        $process = new Process(['sudo', '-n', 'python3', '-I', '-c', development_caddy_access_helper($command), 'set', 'D', '-m', 'u:caddy:--x', '--', "$root/checkout"]);

        expect($process->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('Root acts only on a path that root owns from / down')
            ->and(new Process(['getfacl', '-p', "$root/checkout"])->mustRun()->getOutput())->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('never gives a saved ACL to a private file that the worker moves to the saved name', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();
    $retained = null;

    try {
        $checkout = "$root/checkout";
        development_caddy_access_share($root);
        // The Gateway writes the environment file with mode 0600, so its mask hides it from the worker's entry.
        chmod("$checkout/.env", 0o600);
        // After the snapshot saved index.html, the worker moves the managed user's environment file over it and
        // fails the walk, so recovery meets the environment file at the saved name.
        mkdir("$root/bin");
        file_put_contents("$root/bin/setfacl", <<<BASH
            #!/bin/bash
            for argument in "\$@"; do
                if [ "\$argument" = u:caddy:r-X ]; then
                    sudo -n -u nobody mv -f -- '$checkout/.env' '$checkout/web/site/index.html'
                    exit 1
                fi
            done
            exec /usr/bin/setfacl "\$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]), 'nobody');

        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        expect($process->setInput($command->input)->run())->not->toBe(0);
        $retained = development_caddy_access_retained($process);
        $moved = "$checkout/web/site/index.html";
        $acl = new Process(['getfacl', '-cp', $moved])->mustRun()->getOutput();

        expect(file_exists("$checkout/.env"))->toBeFalse()
            ->and(file_get_contents($moved))->toBe('SECRET=value')
            ->and($acl)->toContain('mask::---')
            ->and($acl)->toContain('other::---')
            ->and(new Process(['sudo', '-n', '-u', 'nobody', 'cat', $moved])->run())->not->toBe(0)
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', $moved])->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain("$moved: Replaced since the snapshot")
            ->and($retained)->toStartWith('/var/tmp/orbit-caddy-acl.');
    } finally {
        if ($retained !== null) {
            new Filesystem()->deleteDirectory($retained);
        }
        new Process(['sudo', '-n', 'rm', '-rf', '--', $root])->run();
    }
});

it('keeps a private file that the worker moves into the Web root hidden from Caddy and the worker', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $checkout = "$root/checkout";
        development_caddy_access_share($root);
        chmod("$checkout/.env", 0o600);
        new Process(['sudo', '-n', '-u', 'nobody', 'mv', '--', "$checkout/.env", "$checkout/web/site/leak.txt"])->mustRun();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]), 'nobody');

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->toBe(0, $process->getErrorOutput());
        $acl = new Process(['getfacl', '-cp', "$checkout/web/site/leak.txt"])->mustRun()->getOutput();

        expect($acl)->toContain('mask::---')
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/leak.txt"])->run())->not->toBe(0)
            ->and(new Process(['sudo', '-n', '-u', 'nobody', 'cat', "$checkout/web/site/leak.txt"])->run())->not->toBe(0)
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/index.html"])->mustRun()->getOutput())->toBe('first page');
    } finally {
        new Process(['sudo', '-n', 'rm', '-rf', '--', $root])->run();
    }
});

it('fails and restores access when the mode of the Web root hides it from Caddy', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        chmod("$root/checkout/web/site", 0o700);
        $before = new Process(['getfacl', '-R', '-p', "$root/checkout/web/site"])->mustRun()->getOutput();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain("Caddy cannot read the Web root $root/checkout/web/site")
            ->and(development_caddy_access_retained($process))->toBeNull()
            ->and(new Process(['getfacl', '-R', '-p', "$root/checkout/web/site"])->mustRun()->getOutput())->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('never widens a private file that reuses the inode number of a saved entry', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();
    $retained = null;

    try {
        $checkout = "$root/checkout";
        development_caddy_access_share($root);
        // After the snapshot saved index.html, at the first change, the worker removes it, waits until the managed
        // user writes a mode 0600 file that reuses the freed inode number, and moves that file onto the saved name.
        // Then the worker fails the walk.
        mkdir("$root/bin");
        file_put_contents("$root/bin/setfacl", <<<BASH
            #!/bin/bash
            if [ ! -e '$root/attempted' ]; then
                touch '$root/attempted'
                inode=\$(stat -c %i -- '$checkout/web/site/index.html')
                sudo -n -u nobody rm -f -- '$checkout/web/site/index.html'
                for attempt in \$(seq 1 200); do
                    printf 'SECRET=value' > '$checkout/secret-'\$attempt
                    chmod 0600 -- '$checkout/secret-'\$attempt
                    if [ "\$(stat -c %i -- '$checkout/secret-'\$attempt)" = "\$inode" ]; then
                        sudo -n -u nobody mv -f -- '$checkout/secret-'\$attempt '$checkout/web/site/index.html'
                        touch '$root/reused'
                        break
                    fi
                done
            fi
            for argument in "\$@"; do
                if [ "\$argument" = u:caddy:r-X ]; then exit 1; fi
            done
            exec /usr/bin/setfacl "\$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]), 'nobody');

        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        expect($process->setInput($command->input)->run())->not->toBe(0);
        $retained = development_caddy_access_retained($process);
        $moved = "$checkout/web/site/index.html";
        clearstatcache();
        $acl = new Process(['getfacl', '-cp', $moved])->mustRun()->getOutput();

        expect(file_exists("$root/reused"))->toBeTrue('The filesystem never reused the inode number.')
            ->and(file_get_contents($moved))->toBe('SECRET=value')
            ->and(fileperms($moved) & 0o777)->toBe(0o600)
            ->and($acl)->toContain('mask::---')
            ->and(new Process(['sudo', '-n', '-u', 'nobody', 'cat', $moved])->run())->not->toBe(0)
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', $moved])->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain("$moved: Replaced since the snapshot")
            ->and($retained)->toStartWith('/var/tmp/orbit-caddy-acl.');
    } finally {
        if ($retained !== null) {
            new Filesystem()->deleteDirectory($retained);
        }
        new Process(['sudo', '-n', 'rm', '-rf', '--', $root])->run();
    }
});

it('restores nothing on a filesystem that reports no inode generation', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    // tmpfs reports no inode generation, so the restore cannot tell a reused inode number apart.
    $root = '/dev/shm/orbit-caddy-access-'.Str::uuid();
    mkdir("$root/snapshot", 0o700, true);

    try {
        file_put_contents("$root/page.html", 'page');
        $helper = development_caddy_access_helper(new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')])));
        new Process(['python3', '-I', '-c', $helper, 'save', "$root/snapshot", '-', "$root/page.html"])->mustRun();
        $process = new Process(['python3', '-I', '-c', $helper, 'restore']);

        expect($process->setInput(file_get_contents("$root/snapshot/".posix_geteuid()))->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain("$root/page.html: Replaced since the snapshot");
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('never restores a setuid bit that the entry lost during the walk', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $tool = "$root/checkout/web/site/tool";
        file_put_contents($tool, 'tool');
        chmod($tool, 0o4755);
        // After the snapshot saved the setuid file, a write clears its setuid bit, and the walk fails.
        mkdir("$root/bin");
        file_put_contents("$root/bin/setfacl", <<<BASH
            #!/bin/bash
            for argument in "\$@"; do
                if [ "\$argument" = u:caddy:r-X ]; then
                    chmod u-s -- '$tool'
                    exit 1
                fi
            done
            exec /usr/bin/setfacl "\$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));

        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        expect($process->setInput($command->input)->run())->not->toBe(0)
            ->and(development_caddy_access_retained($process))->toBeNull($process->getErrorOutput());
        clearstatcache();
        expect(fileperms($tool) & 0o7777)->toBe(0o755);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('names the served entries that Caddy cannot read', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $checkout = "$root/checkout";
        mkdir("$checkout/public", 0o755);
        mkdir("$checkout/storage/app/public", 0o755, true);
        symlink('../storage/app/public', "$checkout/public/storage");
        file_put_contents("$checkout/public/index.html", 'page');
        file_put_contents("$checkout/public/secret.txt", 'secret');
        chmod("$checkout/public/secret.txt", 0o600);
        file_put_contents("$checkout/storage/app/public/hidden.txt", 'hidden');
        chmod("$checkout/storage/app/public/hidden.txt", 0o600);
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'public')]));

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->toBe(0, $process->getErrorOutput())
            ->and($process->getErrorOutput())->toContain('Warning: Caddy cannot read 2 of the entries it serves')
            ->and($process->getErrorOutput())->toContain("$checkout/public/secret.txt")
            ->and($process->getErrorOutput())->toContain("$checkout/storage/app/public/hidden.txt")
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/public/index.html"])->mustRun()->getOutput())->toBe('page');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('fails and restores access when the mode of the public storage hides it from Caddy', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $checkout = "$root/checkout";
        mkdir("$checkout/public", 0o755);
        mkdir("$checkout/storage/app/public", 0o700, true);
        symlink('../storage/app/public', "$checkout/public/storage");
        $before = new Process(['getfacl', '-R', '-p', "$checkout/public", "$checkout/storage/app/public"])->mustRun()->getOutput();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'public')]));

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain("Caddy cannot read the public storage $checkout/storage/app/public")
            ->and(development_caddy_access_retained($process))->toBeNull()
            ->and(new Process(['getfacl', '-R', '-p', "$checkout/public", "$checkout/storage/app/public"])->mustRun()->getOutput())->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

/** The Python helper that makes every ACL change of the walk. */
function development_caddy_access_helper(RemoteCommand $command): string
{
    preg_match("/fd_acl=\\$\\(cat <<'PYTHON'\\n(.*?)\\nPYTHON\\n/s", (string) $command->input, $helper);

    return $helper[1];
}

/** Shares the fixture with the task worker `nobody`, as a shared task checkout does: both accounts get `rwX`. */
function development_caddy_access_share(string $root): void
{
    $me = posix_geteuid();
    new Process(['setfacl', '-R', '-m', "u:nobody:rwX,u:$me:rwX,d:u:nobody:rwX,d:u:$me:rwX", $root])->mustRun();
}

/** The snapshot directory that a failed recovery retained and named, or null. */
function development_caddy_access_retained(Process $process): ?string
{
    return preg_match('/retained ACL snapshots: (\S+)/', $process->getErrorOutput(), $match) === 1 ? $match[1] : null;
}

function development_caddy_access_fixture(?string $parent = null): string
{
    // A restore needs inode generations, which tmpfs does not report, so a tmpfs temporary directory gives way to the
    // on-disk /var/tmp.
    $parent ??= trim(new Process(['stat', '-f', '-c', '%T', sys_get_temp_dir()])->mustRun()->getOutput()) === 'tmpfs' ? '/var/tmp' : sys_get_temp_dir();
    $root = $parent.'/orbit-caddy-access-'.Str::uuid();
    // The directories above the Web root are closed, so the walk grants Caddy passage. Git creates the Web root
    // and what it serves with the usual modes.
    mkdir("$root/checkout/web/site", 0o700, true);
    chmod("$root/checkout/web/site", 0o755);
    new Process(['git', 'init', '--quiet', "$root/checkout"])->mustRun();
    file_put_contents("$root/checkout/web/site/index.html", 'first page');
    file_put_contents("$root/checkout/.env", 'SECRET=value');
    file_put_contents("$root/checkout/source.txt", 'private source');
    file_put_contents("$root/checkout/web/private.txt", 'private sibling');

    return $root;
}

function development_caddy_access_site(string $checkout, string $webRoot): DevelopmentSite
{
    return new DevelopmentSite(1, '10.44.0.2', 'app-instance-1', $checkout, $webRoot, null, 'hello.alpha.test');
}
