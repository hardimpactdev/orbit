<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

use Illuminate\Support\Facades\Config;

/** Suppress checkout-controlled hooks and fsmonitor for Gateway shell reads and mutations. */
final readonly class WorkspaceGit
{
    /**
     * `checkout_fetch` runs a credentialed fetch for a development checkout through `git_read`. The checkout's own
     * `.git/config` is written by the managed user, and so by every setup step, development Process, and agent that
     * runs as that user. Command-line settings override that file: no credential helper, hook, fsmonitor, or
     * alternate-refs command runs, redirects are not followed, and only HTTPS may transport the fetch, so a planted
     * `url.*.insteadOf` cannot reach `ext::`, SSH, or a local command while the token is in the environment.
     * Each transport is set on the command line, because a planted `protocol.<name>.allow` outranks `protocol.allow`.
     * `orbit.git.checkout_protocols` lists the allowed ones; tests add `file` for local origins.
     */
    public static function fetchPreamble(): string
    {
        $allowed = array_filter(Config::array('orbit.git.checkout_protocols', ['https']), is_string(...));
        // A planted `protocol.<name>.allow` outranks the default, so each transport is set on the command line.
        $protocols = implode(' ', array_map(
            static fn (string $protocol): string => '-c protocol.'.$protocol.'.allow='.(in_array($protocol, $allowed, true) ? 'always' : 'never'),
            ['https', 'http', 'ssh', 'git', 'file', 'ext'],
        ));

        return "\n".'checkout_fetch() { git_read git -c credential.helper= -c core.hooksPath=/dev/null -c core.fsmonitor=false'
            .' -c core.alternateRefsCommand=true -c protocol.allow=never '.$protocols.' -c http.followRedirects=false "$@"; }'."\n";
    }

    public static function bashPreamble(): string
    {
        return 'git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false "$@"; }'."\n";
    }
}
