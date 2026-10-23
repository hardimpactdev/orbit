<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Instances\NativeDevelopmentSourceAccess;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LinuxHost;
use Tests\Support\LocalAgentBinary;

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

    $walked = NativeDevelopmentSourceAccess::walkedSites(collect([
        $site('/apps/served'),
        $site(''),
        $site('/apps/proxied', 'http://127.0.0.1:4788'),
    ]));

    expect($site('/apps/proxied', 'http://127.0.0.1:4788')->isProxy())->toBeTrue()
        ->and($walked->pluck('checkoutPath')->all())->toBe(['/apps/served']);
});

it('serves a nested Web root while protecting source and preserving shared parent modes', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $root = development_caddy_access_fixture();

    try {
        $homeMode = fileperms($root) & 0o777;
        $sourceMode = fileperms("$root/checkout/.env") & 0o777;
        $process = development_caddy_access([development_caddy_access_site("$root/checkout", 'web/site')]);
        development_caddy_access_granted($process);
        development_caddy_access_granted($process);

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
        $process = development_caddy_access([development_caddy_access_site($checkout, 'web/site')]);
        $gitMode = fileperms("$home/.git") & 0o777;
        development_caddy_access_granted($process);
        development_caddy_access_granted($process);

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
        $process = development_caddy_access([development_caddy_access_site("$root/checkout", 'web/site')]);

        expect(development_caddy_access_refusal($process)['code'])->toBe('instances.caddy_access_invalid');
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
        $process = development_caddy_access([development_caddy_access_site("$root/checkout", ltrim($relative.'/public', '/'))]);
        development_caddy_access_granted($process);

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
        $process = development_caddy_access([
            development_caddy_access_site($nested, 'public'),
            development_caddy_access_site("$root/checkout", 'web/site'),
        ]);
        development_caddy_access_granted($process);

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
                case "\$argument" in --restore) {$failRestore} ;; esac
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
        $process = development_caddy_access([development_caddy_access_site($checkout, 'web/site')], "$root/bin");
        $error = development_caddy_access_refusal($process);
        expect($error['code'])->toBe($failRecovery ? 'instances.caddy_access_recovery_failed' : 'instances.caddy_access_failed');
        // Nothing lands in the Git directory, which the checkout's own programs can write.
        expect(new Filesystem()->glob("$root/checkout/.git/orbit-caddy-acl.*"))->toBe([]);
        $retained = $error['details']['snapshots'] ?? null;
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

it('finishes a walk while setup removes and replaces files under the checkout and the Web root', function (int $run): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    // Five cases each own one real walk. The agent runs one walk per managed account at a time.
    $roots = [development_caddy_access_fixture()];
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
                printf 'acl-walk:%s\\n' "\$phase" >> "$root/walk-phases"
                IFS= read -r acknowledgement < "$root/walk-continue"
                test "\$acknowledgement" = continue || exit 1
            fi
            exec /usr/bin/setfacl "\$@"
            BASH);
            chmod("$root/bin/setfacl", 0o700);
        }
        $failures = [];
        $completedWalks = 0;
        foreach ($roots as $root) {
            $checkout = "$root/checkout";
            foreach (["$checkout/vendor", "$checkout/web/site/build"] as $directory) {
                foreach (['vanished', 'replaced'] as $entry) {
                    new Filesystem()->ensureDirectoryExists("$directory/$entry");
                    file_put_contents("$directory/$entry/file", 'old');
                }
            }
            $processes[$root] = $process = development_caddy_access([development_caddy_access_site($checkout, 'web/site')], "$root/bin");
            $process->start();
        }
        foreach (['checkout' => 'vendor', 'web' => 'web/site/build'] as $phase => $relative) {
            foreach ($processes as $root => $process) {
                $directory = "$root/checkout/$relative";
                while (! str_contains((string) @file_get_contents("$root/walk-phases"), "acl-walk:$phase\n")) {
                    expect($process->isRunning())->toBeTrue($process->getOutput().$process->getErrorOutput());
                    usleep(10_000);
                }
                new Filesystem()->deleteDirectory("$directory/vanished");
                new Filesystem()->deleteDirectory("$directory/replaced");
                mkdir("$directory/replaced", 0o755);
                file_put_contents("$directory/replaced/file", "replacement $run");
                file_put_contents("$root/walk-continue", "continue\n");
            }
        }
        foreach ($processes as $process) {
            $process->wait();
            $result = development_caddy_access_result($process);
            if ($result['ok'] !== true) {
                $failures[] = $result['error'];
            }
            $completedWalks++;
        }

        expect($failures)->toBe([]);
        expect($completedWalks)->toBe(1);
        foreach ($roots as $root) {
            $checkout = "$root/checkout";
            // A build after the walk creates its output with the usual modes; the default ACLs make it readable.
            mkdir("$checkout/web/site/build/late", 0o755, true);
            file_put_contents("$checkout/web/site/build/late/app.js", 'late asset');
            $reads = development_caddy_access_reads([
                "$checkout/web/site/build/replaced/file", "$checkout/vendor/replaced/file",
                "$checkout/web/site/build/late/app.js", "$checkout/web/site/index.html",
                "$checkout/.env", "$checkout/source.txt", "$checkout/web/private.txt",
            ]);
            expect($reads["$checkout/web/site/build/replaced/file"])->toBe("replacement $run")
                ->and($reads["$checkout/vendor/replaced/file"])->toBeNull()
                ->and($reads["$checkout/web/site/build/late/app.js"])->toBe('late asset')
                ->and($reads["$checkout/web/site/index.html"])->toBe('first page');
            foreach (['.env', 'source.txt', 'web/private.txt'] as $private) {
                expect($reads["$checkout/$private"])->toBeNull();
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
})->with([1, 2, 3, 4, 5]);

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
        $process = development_caddy_access([development_caddy_access_site("$root/checkout", 'web/site')], "$root/bin");
        $result = development_caddy_access_result($process);

        expect($result['ok'])->toBe($succeeds, json_encode($result, JSON_THROW_ON_ERROR));
        if ($succeeds) {
            expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/web/site/index.html"])->mustRun()->getOutput())->toBe('first page')
                ->and(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$root/checkout/source.txt"])->run())->toBe(1);
        } else {
            expect($result['error']['details']['reason'])->toContain($error);
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
        $process = development_caddy_access([development_caddy_access_site($checkout, 'web/site')]);
        $error = development_caddy_access_refusal($process);
        // The walk failed after it changed ACLs, so recovery put back the saved ones.
        expect($error['code'])->toBe('instances.caddy_access_failed')
            ->and($error['details']['reason'])->toContain("$checkout/$entry: Operation not permitted")
            ->and(new Process(['getfacl', '-p', "$checkout/$entry"])->mustRun()->getOutput())->toBe($foreign)
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
        $failing = development_caddy_access([development_caddy_access_site($checkout, 'web/site')], "$root/bin");
        expect(development_caddy_access_refusal($failing)['code'])->toBe('instances.caddy_access_failed')
            ->and($granted())->toBe($before);
        development_caddy_access_granted(development_caddy_access([development_caddy_access_site($checkout, 'web/site')]));
        expect(new Process(['sudo', '-n', '-u', 'caddy', 'cat', "$checkout/web/site/index.html"])->mustRun()->getOutput())->toBe('first page')
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
        $process = development_caddy_access([development_caddy_access_site("$root/checkout", 'web/site')]);

        foreach ([1, 2] as $run) {
            development_caddy_access_granted($process);
            expect(new Process(['getfacl', '-cp', "$root/checkout"])->mustRun()->getOutput())->toContain('user:caddy:--x')
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
        $process = development_caddy_access([development_caddy_access_site($checkout, 'web/site')]);
        expect(development_caddy_access_granted($process)['unreadable'])->toBe(["$checkout/web/site/leak.txt"]);
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
        $process = development_caddy_access([development_caddy_access_site("$root/checkout", 'web/site')]);
        $error = development_caddy_access_refusal($process);
        expect($error['code'])->toBe('instances.caddy_access_failed')
            ->and($error['details']['reason'])->toContain("Caddy cannot read the Web root $root/checkout/web/site")
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
        $process = development_caddy_access([development_caddy_access_site($checkout, 'public')]);
        expect(development_caddy_access_granted($process)['unreadable'])->toEqualCanonicalizing(["$checkout/public/secret.txt", "$checkout/storage/app/public/hidden.txt"])
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
        $process = development_caddy_access([development_caddy_access_site($checkout, 'public')]);
        $error = development_caddy_access_refusal($process);
        expect($error['code'])->toBe('instances.caddy_access_failed')
            ->and($error['details']['reason'])->toContain("Caddy cannot read the public storage $checkout/storage/app/public")
            ->and(new Process(['getfacl', '-R', '-p', "$checkout/public", "$checkout/storage/app/public"])->mustRun()->getOutput())->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

/**
 * The built agent's `instances.grant-development-caddy-access` for these sites, run as root for the test account, as
 * the Gateway sends it to a Node. `$bin` goes first on the agent's `PATH` to stand in for a walk tool.
 *
 * @param  list<DevelopmentSite>  $sites
 */
function development_caddy_access(array $sites, ?string $bin = null): Process
{
    $binary = LocalAgentBinary::path();
    $envelope = json_encode(['agent_sha256' => hash_file('sha256', $binary), 'deadline_ms' => 600_000, 'input' => [
        'user' => posix_getpwuid(posix_geteuid())['name'],
        'sites' => NativeDevelopmentSourceAccess::walkedSites(collect($sites))->map(static fn (DevelopmentSite $site): array => [
            'checkout' => $site->checkoutPath,
            'relative_root' => $site->documentRoot,
            'application' => $site->applicationDirectory(),
        ])->all(),
    ]], JSON_THROW_ON_ERROR);
    $path = ($bin === null ? '' : "$bin:").getenv('PATH');
    $arguments = LocalAgentBinary::rootArguments(['sudo', '-n', 'orbit-agent', 'command', 'instances.grant-development-caddy-access'], ['PATH' => $path]);

    return new Process($arguments ?? [], timeout: 600)
        ->setInput($envelope);
}

/** @return array{ok: bool, data?: array<string, mixed>, error?: array{code: string, message: string, details: array<string, mixed>}} */
function development_caddy_access_result(Process $process): array
{
    if (! $process->isStarted()) {
        $process->run();
    }

    return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function development_caddy_access_granted(Process $process): array
{
    $process->run();
    $result = development_caddy_access_result($process);
    expect($result['ok'])->toBeTrue(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['data'];
}

/** @return array{code: string, message: string, details: array<string, mixed>} */
function development_caddy_access_refusal(Process $process): array
{
    $process->run();
    $result = development_caddy_access_result($process);
    expect($result['ok'])->toBeFalse(json_encode($result, JSON_THROW_ON_ERROR));

    return $result['error'];
}

/** @param list<string> $paths
 * @return array<string, string|null> Actual file bodies, or null only for a kernel permission denial.
 */
function development_caddy_access_reads(array $paths): array
{
    // One identity switch, not one sudo/PAM session per read. Missing files and other errors still fail the process.
    $reader = <<<'PYTHON'
    import json
    import sys

    reads = {}
    for path in sys.argv[1:]:
        try:
            with open(path) as file:
                reads[path] = file.read()
        except PermissionError:
            reads[path] = None
    print(json.dumps(reads))
    PYTHON;
    $process = new Process(['sudo', '-n', '-u', 'caddy', 'python3', '-S', '-c', $reader, ...$paths]);

    return json_decode($process->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
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
