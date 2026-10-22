<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Instances\DevelopmentCaddyAccessCommand;
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
        $failRestore = $failRecovery ? 'exit 1' : '';
        file_put_contents("$root/bin/setfacl", <<<BASH
            #!/bin/bash
            for argument in "\$@"; do
                if [ "\$argument" = 'u:caddy:r-X' ]; then exit 1; fi
                case "\$argument" in --restore=*) {$failRestore} ;; esac
            done
            exec /usr/bin/setfacl "\$@"
            BASH);
        chmod("$root/bin/setfacl", 0o700);
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
        // Nothing lands in the Git directory, which the checkout's own programs can write.
        expect(new Filesystem()->glob("$root/checkout/.git/orbit-caddy-acl.*"))->toBe([]);
        $retained = development_caddy_access_retained($process);
        if ($failRecovery) {
            expect($retained)->toStartWith('/var/tmp/orbit-caddy-acl.')
                ->and(fileowner($retained))->toBe(posix_geteuid())
                ->and(fileperms($retained) & 0o777)->toBe(0o700)
                ->and(filesize("$retained/root"))->toBe(0);
            // The test account owns every saved entry, so its own restore of the retained snapshot puts them back.
            new Process(['setfacl', "--restore=$retained/managed"])->mustRun();
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

    // Two private checkouts let the ten real walks run in pairs, without racing each other's ACL grants.
    $roots = [development_caddy_access_fixture(), development_caddy_access_fixture()];
    $processes = [];

    try {
        foreach ($roots as $root) {
            mkdir("$root/bin");
            posix_mkfifo("$root/walk-continue", 0o600);
            // `find -exec` has enumerated real directories, but setfacl has not opened them yet. Hold that boundary
            // until the test removes one enumerated directory and replaces another, like a build or Composer does.
            file_put_contents("$root/bin/setfacl", <<<BASH
            #!/bin/bash
            phase=
            for argument in "\$@"; do
                case "\$argument" in
                    d:u:caddy:---) phase=checkout ;;
                    d:u:caddy:r-x) phase=web ;;
                esac
            done
            if [ -n "\$phase" ]; then
                printf 'acl-walk:%s\\n' "\$phase"
                IFS= read -r acknowledgement < "$root/walk-continue"
                test "\$acknowledgement" = continue || exit 1
            fi
            exec /usr/bin/setfacl "\$@"
            BASH);
            chmod("$root/bin/setfacl", 0o700);
        }
        $failures = [];
        $completedWalks = 0;
        foreach (range(1, 5) as $run) {
            foreach ($roots as $root) {
                $checkout = "$root/checkout";
                foreach (["$checkout/vendor", "$checkout/web/site/build"] as $directory) {
                    foreach (['vanished', 'replaced'] as $entry) {
                        new Filesystem()->ensureDirectoryExists("$directory/$entry");
                        file_put_contents("$directory/$entry/file", 'old');
                    }
                }
                $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]));
                $processes[$root] = $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
                $process->setInput($command->input)->start();
            }
            foreach ($processes as $root => $process) {
                $checkout = "$root/checkout";
                foreach (['checkout' => "$checkout/vendor", 'web' => "$checkout/web/site/build"] as $phase => $directory) {
                    expect($process->waitUntil(static fn (): bool => str_contains($process->getOutput(), "acl-walk:$phase\n")))
                        ->toBeTrue($process->getErrorOutput());
                    new Filesystem()->deleteDirectory("$directory/vanished");
                    new Filesystem()->deleteDirectory("$directory/replaced");
                    mkdir("$directory/replaced", 0o755);
                    file_put_contents("$directory/replaced/file", "replacement $run");
                    file_put_contents("$root/walk-continue", "continue\n");
                }
            }
            foreach ($processes as $process) {
                if ($process->wait() !== 0) {
                    $failures[] = $process->getErrorOutput();
                }
                $completedWalks++;
            }
        }

        expect($failures)->toBe([]);
        expect($completedWalks)->toBe(10);
        foreach ($roots as $root) {
            $checkout = "$root/checkout";
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/build/replaced/file"])->mustRun()->getOutput())->toBe('replacement 5')
                ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/vendor/replaced/file"])->run())->toBe(1);
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
        }
    } finally {
        foreach ($processes as $process) {
            $process->stop(0);
        }
        foreach ($roots as $root) {
            new Filesystem()->deleteDirectory($root);
        }
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

it('fails loudly on an entry the managed user does not own and restores what it granted', function (string $entry): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $checkout = "$root/checkout";
        file_put_contents("$checkout/$entry", 'foreign');
        new Process(['sudo', '-n', 'chown', 'root', "$checkout/$entry"])->mustRun();
        $foreign = new Process(['getfacl', '-p', "$checkout/$entry"])->mustRun()->getOutput();
        $granted = static fn (): string => new Process(['getfacl', '-R', '-p', "$checkout/web/site"])->mustRun()->getOutput()
            .new Process(['getfacl', '-p', "$checkout/web", $checkout, $root])->mustRun()->getOutput();
        $before = $granted();
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]));

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain("$checkout/$entry: Operation not permitted")
            ->and(new Process(['getfacl', '-p', "$checkout/$entry"])->mustRun()->getOutput())->toBe($foreign);
        // The walk failed after it changed ACLs, so recovery put back the saved ones.
        expect(development_caddy_access_retained($process))->toBeNull($process->getErrorOutput())
            ->and($granted())->toBe($before);
    } finally {
        new Process(['sudo', '-n', 'rm', '-rf', '--', $root])->run();
    }
})->with(['outside the Web root' => 'foreign.txt', 'inside the Web root' => 'web/site/foreign.html']);

it('restores the root directory above the checkout after a failed walk', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    // Caddy cannot pass this root directory above the checkout, so root grants the passage.
    $base = '/var/tmp/orbit-caddy-access-'.Str::uuid();
    new Process(['sudo', '-n', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0700', $base])->mustRun();

    try {
        new Process(['sudo', '-n', 'setfacl', '-m', 'u:'.posix_geteuid().':rwx', $base])->mustRun();
        $root = development_caddy_access_fixture($base);
        $checkout = "$root/checkout";
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
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]));

        $process = new Process($command->arguments, env: ['PATH' => "$root/bin:".getenv('PATH')]);
        expect($process->setInput($command->input)->run())->not->toBe(0)
            ->and(development_caddy_access_retained($process))->toBeNull($process->getErrorOutput())
            ->and($granted())->toBe($before);

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->toBe(0, $process->getErrorOutput())
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/index.html"])->mustRun()->getOutput())->toBe('first page')
            ->and(new Process(['getfacl', '-cp', $base])->mustRun()->getOutput())->toContain('user:caddy:--x')
            ->and(fileowner($base))->toBe(0)
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/source.txt"])->run())->toBe(1);
    } finally {
        new Process(['sudo', '-n', 'rm', '-rf', '--', $base])->run();
    }
});

it('checks what Caddy can pass with its ACL entry, not only the mode', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        // The modes let every account pass, but the deny gives Caddy its own entry without execute.
        foreach (["$root", "$root/checkout", "$root/checkout/web"] as $directory) {
            chmod($directory, 0o755);
        }
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site("$root/checkout", 'web/site')]));

        foreach ([1, 2] as $run) {
            $process = new Process($command->arguments)->setInput($command->input);
            expect($process->run())->toBe(0, $process->getErrorOutput())
                ->and(new Process(['getfacl', '-cp', "$root/checkout"])->mustRun()->getOutput())->toContain('user:caddy:--x')
                ->and(new Process(['getfacl', '-cp', "$root/checkout/web"])->mustRun()->getOutput())->toContain('user:caddy:--x')
                ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/index.html"])->mustRun()->getOutput())->toBe('first page')
                ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/private.txt"])->run())->toBe(1);
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('keeps a mode 0600 file in the Web root hidden from Caddy', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $checkout = "$root/checkout";
        chmod("$checkout/.env", 0o600);
        rename("$checkout/.env", "$checkout/web/site/leak.txt");
        $command = new DevelopmentCaddyAccessCommand()->command(collect([development_caddy_access_site($checkout, 'web/site')]));

        $process = new Process($command->arguments)->setInput($command->input);
        expect($process->run())->toBe(0, $process->getErrorOutput());
        $acl = new Process(['getfacl', '-cp', "$checkout/web/site/leak.txt"])->mustRun()->getOutput();

        clearstatcache();
        expect($acl)->toContain('mask::---')
            ->and(fileperms("$checkout/web/site/leak.txt") & 0o777)->toBe(0o600)
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/leak.txt"])->run())->not->toBe(0)
            ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/index.html"])->mustRun()->getOutput())->toBe('first page');
    } finally {
        new Filesystem()->deleteDirectory($root);
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

/** The snapshot directory that a failed recovery retained and named, or null. */
function development_caddy_access_retained(Process $process): ?string
{
    return preg_match('/retained ACL snapshots: (\S+)/', $process->getErrorOutput(), $match) === 1 ? $match[1] : null;
}

function development_caddy_access_fixture(?string $parent = null): string
{
    $parent ??= sys_get_temp_dir();
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
