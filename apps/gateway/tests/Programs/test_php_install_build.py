"""php.install-build against a fake Node: dpkg, apt, systemctl, and PHP are shell fakes over a temporary root."""
import hashlib
import importlib.machinery
import importlib.util
import json
import os
import shutil
import socket
import struct
import subprocess
import tempfile
import threading
import unittest
from pathlib import Path

from harness import execute, result

FAKES = {
    'sudo': '[ "$1" = -n ] && shift; exec "$@"',
    'install': 'args=(); skip=0; for a in "$@"; do [ "$skip" = 1 ] && { skip=0; continue; }; case "$a" in -o|-g) skip=1;; '
               '*) args+=("$a");; esac; done; exec /usr/bin/install "${args[@]}"',
    # Installed packages live in $STATE/installed/<name>, holding the version.
    'dpkg-query': 'for f in "$STATE"/installed/*; do [ -f "$f" ] && printf "%s\\tinstall ok installed\\t%s\\n" '
                  '"$(basename "$f")" "$(cat "$f")"; done; exit 0',
    # A path is owned by a package when $STATE/owned/<file name> exists.
    'dpkg': 'case "$1" in --print-architecture) echo amd64;; -S) shift; [ "$1" = -- ] && shift; rc=0; '
            '[ -f "$STATE/dpkg-S-fails" ] && { echo "dpkg-query: error: database is locked" >&2; exit 2; }; '
            'echo "dpkg -S $#" >> "$STATE/calls"; for p in "$@"; do if [ -f "$STATE/owned/$(basename "$p")" ]; then '
            'echo "php8.5-common: $p"; else echo "dpkg-query: no path found matching pattern $p" >&2; rc=1; fi; done; '
            'exit $rc;; '
            # dpkg --verify prints $STATE/verify-<package>, the lines of files that differ from the package.
            '--verify) shift; [ "$1" = -- ] && shift; for p in "$@"; do cat "$STATE/verify-$p" 2>/dev/null; done;; --purge|--remove) op="${1#--}"; shift; [ "$1" = -- ] && shift; '
            'echo "$op $*" >> "$STATE/calls"; [ -f "$STATE/fail-$op" ] && exit 1; '
            'for p in "$@"; do rm -f "$STATE/installed/$p"; done;; esac',
    # A fake .deb is a text file: "<name> <version> <architecture>".
    # dpkg-deb --build refuses a tree whose DEBIAN/conffiles names a file that is not in it, as the real one does.
    # $STATE/build-writes-nothing makes it exit 0 without a file, so the program must not trust the exit code.
    # dpkg-deb --extract writes the payload kept in $STATE/payload/<name>_<version>/.
    'dpkg-deb': 'case "$1" in --extract) shift; [ "$1" = -- ] && shift; read -r n v a < "$1"; '
                '[ -d "$STATE/payload/${n}_$v" ] && cp -r "$STATE/payload/${n}_$v/." "$2/"; exit 0;; --build) shift; [ "$1" = -- ] && shift; tree="$1"; out="$2"; '
                '[ -f "$STATE/build-writes-nothing" ] && exit 0; '
                'if [ -f "$tree/DEBIAN/conffiles" ]; then while read -r c; do [ -e "$tree$c" ] || exit 2; done '
                '< "$tree/DEBIAN/conffiles"; fi; n=$(sed -n "s/^Package: //p" "$tree/DEBIAN/control"); '
                'v=$(sed -n "s/^Version: //p" "$tree/DEBIAN/control"); printf "%s %s amd64\\n" "$n" "$v" > "$out"; '
                'cp "$tree/DEBIAN/conffiles" "$STATE/built-conffiles-$n" 2>/dev/null; exit 0;; esac; '
                'read -r n v a < "$2"; printf "Package: %s\\nVersion: %s\\nArchitecture: %s\\n" "$n" "$v" "$a"',
    'curl': 'out=""; for a in "$@"; do [ "$prev" = --output ] && out="$a"; prev="$a"; done; url="${@: -1}"; '
            'echo "curl $url" >> "$STATE/calls"; src="$STATE/served/$(echo "$url" | sed "s|^https://||; s|/|_|g")"; '
            '[ -f "$src" ] || exit 22; cp "$src" "$out"',
    'apt-get': 'echo "apt-get $*" >> "$STATE/calls"; [ -f "$STATE/fail-every-install" ] && exit 100; '
               '[ -f "$STATE/fail-install" ] && { rm "$STATE/fail-install"; exit 100; }; '
               'for a in "$@"; do case "$a" in *.deb) read -r n v x < "$a"; '
               '[ -f "$STATE/mismatch-$n" ] && v=9.9; echo "$v" > "$STATE/installed/$n"; rm -f "$STATE/verify-$n"; '
               # Sury's OpenTelemetry postinst enables every SAPI, including the CLI.
               'if [ "$n" = php8.5-opentelemetry ]; then for sapi in cli fpm; do '
               'ln -sfn /mods/opentelemetry.ini "$ROOT/etc/php/8.5/$sapi/conf.d/20-opentelemetry.ini"; done; fi; '
               # A rollback reinstall that brings an added package back as a dependency.
               '[ -f "$STATE/revives-$v" ] && echo 8.5.12-1 > "$STATE/installed/$(cat "$STATE/revives-$v")"; '
               'if [ -f "$STATE/partial-install" ]; then rm "$STATE/partial-install"; touch "$STATE/fail-every-install"; exit 100; fi; '
               # A package that brings the default pool back, as a package with a recreated conffile would.
               '[ -f "$STATE/recreates-www" ] && echo "[www]" > "$ROOT/etc/php/8.5/fpm/pool.d/www.conf"; '
               'if [ -f "$STATE/breaks-$v" ]; then touch "$STATE/broken"; else rm -f "$STATE/broken"; fi;; '
               'dpkg-repack=*) echo "${a#*=}" > "$STATE/installed/dpkg-repack";; esac; done; exit 0',
    # dpkg-repack --generate writes the build tree. A package's conffiles are listed in $STATE/conffiles-<name>; a
    # conffile is in the tree only when it is on disk under $ROOT.
    'dpkg-repack': '[ "$1" = --generate ] || exit 9; shift; [ "$1" = -- ] && shift; echo "repack $1" >> "$STATE/calls"; '
                   '[ -f "$STATE/repack-no-tree" ] && exit 0; d=$(mktemp -d "$PWD/dpkg-repack.$1.XXXX"); '
                   'mkdir "$d/DEBIAN"; printf "Package: %s\\nVersion: %s\\nArchitecture: amd64\\n" "$1" '
                   '"$(cat "$STATE/installed/$1")" > "$d/DEBIAN/control"; '
                   'if [ -f "$STATE/conffiles-$1" ]; then cp "$STATE/conffiles-$1" "$d/DEBIAN/conffiles"; '
                   'while read -r c; do [ -e "$ROOT$c" ] && { mkdir -p "$d$(dirname "$c")"; cp "$ROOT$c" "$d$c"; }; '
                   'done < "$STATE/conffiles-$1"; fi; exit 0',
    'phpenmod': 'echo "phpenmod $*" >> "$STATE/calls"; ln -sfn /mods/$5.ini "$ROOT/etc/php/$2/$4/conf.d/20-$5.ini"',
    'phpdismod': 'echo "phpdismod $*" >> "$STATE/calls"; rm -f "$ROOT/etc/php/$2/$4/conf.d/20-$5.ini"',
    'systemctl': 'echo "systemctl $*" >> "$STATE/calls"; case "$1" in '
                 'is-active) [ -f "$STATE/active-$3" ];; '
                 'is-enabled) if [ -f "$STATE/masked-$2" ]; then echo masked; exit 1; '
                 'elif [ -f "$STATE/disabled-$2" ]; then echo disabled; exit 1; else echo enabled; fi;; '
                 'disable) [ -f "$STATE/fail-disable" ] && exit 1; touch "$STATE/disabled-${@: -1}"; '
                 'rm -f "$STATE/active-${@: -1}";; '
                 'stop) rm -f "$STATE/active-$2";; '
                 'restart|reload) if [ "$2" = orbit-private-dns.service ]; then '
                 'if [ -f "$STATE/fail-listener-restart" ]; then rm -f "$STATE/active-$2"; '
                 '[ -f "$STATE/break-rollback-self-test" ] && touch "$STATE/listener-invalid"; exit 1; fi; '
                 'ln -sfn "$ROOT/usr/bin/php8.5" "$ROOT/proc/4242/exe"; fi; '
                 '[ -f "$STATE/broken" ] && { rm -f "$STATE/active-$2"; exit 1; }; touch "$STATE/active-$2";; '
                 'list-units) for f in "$STATE"/active-orbit-*; do [ -f "$f" ] && echo "${f##*/active-} loaded active running"; done;; '
                 'show) if [ "${@: -1}" = orbit-private-dns.service ]; then case "$3" in '
                 'MainPID) echo 4242;; WorkingDirectory) cat "$STATE/listener-release";; esac; exit 0; fi; '
                 'echo "{ path=/usr/sbin/php-fpm8.5 ; argv[]=php-fpm8.5 --nodaemonize --fpm-config /etc/orbit/${@: -1}/php-fpm.conf }";; '
                 'esac',
}
# The CLI prints the modules of the links in its conf.d plus core; PHP-FPM does the same for its own conf.d.
PHP = ('#!/usr/bin/env bash\n[ "$1" = -t ] && { configuration="${3%/*}"; master="${configuration##*/}"; '
       '[ -f "$STATE/fpm-invalid" ] || [ -f "$STATE/fpm-invalid-$master" ] && exit 78; exit 0; }\n'
       'if [ "${2:-}" = --self-test ]; then echo "listener self-test" >> "$STATE/calls"; '
       '[ -f "$STATE/listener-invalid" ] && { echo "listener PHP lacks sockets" >&2; exit 78; }; exit 0; fi\n'
       'echo "[PHP Modules]"; echo Core; for f in "$ROOT/etc/php/8.5/{sapi}/conf.d"/20-*.ini; do [ -e "$f" ] || [ -L "$f" ] '
       '|| continue; n="${f##*/20-}"; echo "${n%.ini}"; done; cat "$STATE/extra-modules" 2>/dev/null; true\n')


def deb(name, version):
    return f'{name} {version} amd64\n'.encode()


def package_pin(state, name, version):
    content = deb(name, version)
    digest = hashlib.sha256(content).hexdigest()
    url = f'https://packages.sury.org/php/pool/{name}_{version}_amd64.deb'
    (state / 'served' / url.removeprefix('https://').replace('/', '_')).write_bytes(content)
    return {'name': name, 'version': version, 'architecture': 'amd64', 'url': url, 'sha256': digest}


class InstallBuildTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fixture = tempfile.TemporaryDirectory(dir='/tmp', prefix='orbit-php-fixture-')
        cls.addClassCleanup(cls.fixture.cleanup)
        # Build the immutable Node image once; each case gets its own writable copy.
        root = Path(cls.fixture.name) / 'root'
        state = Path(cls.fixture.name) / 'state'
        bin_directory = Path(cls.fixture.name) / 'bin'
        for path in (state / 'installed', state / 'served', bin_directory,
                     root / 'etc/apt/sources.list.d', root / 'usr/share/keyrings', root / 'usr/sbin',
                     root / 'usr/bin', root / 'etc/php/8.5/cli/conf.d', root / 'etc/php/8.5/fpm/conf.d',
                     root / 'etc/php/8.5/fpm/pool.d'):
            path.mkdir(parents=True)
        for name, body in FAKES.items():
            (bin_directory / name).write_text(f'#!/usr/bin/env bash\n{body}\n')
            (bin_directory / name).chmod(0o755)
        for binary, sapi in (('usr/bin/php8.5', 'cli'), ('usr/sbin/php-fpm8.5', 'fpm')):
            # PHP-FPM also loads the extensions that Orbit requires of every pool.
            required = '' if sapi == 'cli' else 'printf "%s\\n" bcmath curl gd imagick intl mbstring mysqli pdo_mysql ' \
                'pdo_pgsql pdo_sqlite redis simplexml xml zip\n'
            (root / binary).write_text(PHP.replace('{sapi}', sapi).replace('true\n', required + 'true\n'))
            (root / binary).chmod(0o755)
        for module in ('gd', 'intl'):
            for sapi in ('cli', 'fpm'):
                (root / f'etc/php/8.5/{sapi}/conf.d/20-{module}.ini').symlink_to(f'/mods/{module}.ini')
        cls.fixture_pins = {name: package_pin(state, name, '8.5.12-1')
                            for name in ('php8.5-cli', 'php8.5-pcov', 'php-common')}
        for sapi in ('cli', 'fpm'):
            (root / f'etc/php/8.5/{sapi}/conf.d/99-cve-2026-109618-upload-progress.ini').write_text(
                'session.upload_progress.enabled = 0\n')

    def setUp(self):
        # The prefixed FastCGI socket path must fit Unix socket limits, even with a long TMPDIR.
        self.directory = tempfile.TemporaryDirectory(dir='/tmp', prefix='orbit-php-')
        self.addCleanup(self.directory.cleanup)
        shutil.copytree(self.fixture.name, self.directory.name, dirs_exist_ok=True, symlinks=True)
        self.root = Path(self.directory.name) / 'root'
        self.state = Path(self.directory.name) / 'state'
        self.environment = {'PATH': f'{self.directory.name}/bin:{os.environ["PATH"]}', 'STATE': str(self.state),
                            'ROOT': str(self.root)}
        self.pins = {name: dict(pin) for name, pin in self.fixture_pins.items()}

    def pin(self, name, version):
        self.pins[name] = package_pin(self.state, name, version)

    def install(self, name, version):
        (self.state / 'installed' / name).write_text(version + '\n')

    def payload(self, **overrides):
        extensions = ['core', 'gd', 'intl', 'pcov']
        return {'mode': 'apply', 'root': str(self.root), 'mirror': 'https://mirror.example/php',
                'versions': {'8.5': {'packages': list(self.pins.values()), 'extensions': extensions}},
                'pcov': True, 'extras': 'refuse', 'fpm': 'all',
                'repack': {'package': 'dpkg-repack', 'version': '1.54build1'}, **overrides}

    def run_program(self, **overrides):
        return result('php.install-build', self.payload(**overrides), environment=self.environment)

    def calls(self):
        path = self.state / 'calls'
        return path.read_text().splitlines() if path.is_file() else []

    def cached(self):
        return sorted(path.name for path in (self.root / 'var/cache/orbit/php-packages').glob('*.deb'))

    def listener(self, stale=False):
        units = self.root / 'etc/systemd/system'
        units.mkdir(parents=True, exist_ok=True)
        (units / 'orbit-private-dns.service').write_text('[Service]\n')
        release = self.root / 'var/lib/orbit/private-dns/releases/0123456789abcdef'
        release.mkdir(parents=True, exist_ok=True)
        (release / 'serve.php').write_text('<?php')
        (self.state / 'listener-release').write_text(str(release))
        for unit in ('orbit-private-dns.service', 'orbit-private-dns.socket'):
            (self.state / f'active-{unit}').touch()
        proc = self.root / 'proc/4242'
        proc.mkdir(parents=True, exist_ok=True)
        (proc / 'exe').unlink(missing_ok=True)
        (proc / 'exe').symlink_to('/usr/bin/php8.5 (deleted)' if stale else self.root / 'usr/bin/php8.5')

    def test_disables_and_stops_unused_fpm_on_first_install_and_current_build(self):
        for current in (False, True):
            with self.subTest(current=current):
                if current:
                    self.assertEqual(self.run_program(fpm='disabled')[0], 'current')
                (self.state / 'active-php8.5-fpm.service').touch()
                (self.state / 'disabled-php8.5-fpm.service').unlink(missing_ok=True)
                (self.state / 'calls').unlink(missing_ok=True)

                outcome, data = self.run_program(fpm='disabled')

                self.assertIn(outcome, ('current', 'applied'), data)
                self.assertIn('systemctl disable --now php8.5-fpm.service', self.calls())
                self.assertFalse((self.state / 'active-php8.5-fpm.service').exists())
                self.assertTrue((self.state / 'disabled-php8.5-fpm.service').exists())
                self.assertFalse([c for c in self.calls() if c.startswith(('systemctl restart', 'systemctl reload'))])

    def test_vpn_cli_install_selects_the_inactive_fpm_policy(self):
        repository = Path(__file__).resolve().parents[4]
        loader = importlib.machinery.SourceFileLoader('vpn_php_build', str(repository / 'bin/php-build'))
        cli = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
        loader.exec_module(cli)
        recipe = json.loads((repository / 'apps/gateway/resources/machine-software/recipe.json').read_text())

        payload = cli.installer_input(recipe, ['8.5'], profile='vpn')

        self.assertEqual(payload['fpm'], 'disabled')
        self.assertFalse(payload['pcov'])
        self.assertFalse(payload['opentelemetry'])
        self.assertTrue({'sockets', 'pcntl'}.issubset(payload['versions']['8.5']['extensions']))

    def test_preserves_an_existing_fpm_mask(self):
        (self.state / 'masked-php8.5-fpm.service').touch()
        (self.state / 'fail-disable').touch()
        (self.state / 'active-php8.5-fpm.service').touch()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual(outcome, 'applied', data)
        self.assertTrue((self.state / 'masked-php8.5-fpm.service').exists())
        self.assertFalse((self.state / 'active-php8.5-fpm.service').exists())
        self.assertFalse([c for c in self.calls() if c.startswith(('systemctl unmask', 'systemctl enable'))])

    def test_fails_when_unused_fpm_cannot_be_disabled(self):
        (self.state / 'fail-disable').touch()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual(outcome, 'fpm_invalid', data)
        self.assertIn('disabled and inactive', data['reason'])

    def test_refreshes_a_running_listener_after_a_php_change_behind_its_socket(self):
        self.listener()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual(outcome, 'applied', data)
        self.assertTrue(data['listener_restarted'])
        calls = self.calls()
        self.assertLess(calls.index('listener self-test'), calls.index('systemctl restart orbit-private-dns.service'))
        self.assertNotIn('systemctl stop orbit-private-dns.socket', calls)
        self.assertNotIn('systemctl restart orbit-private-dns.socket', calls)

    def test_refreshes_a_deleted_listener_executable_even_when_php_is_current(self):
        self.run_program(fpm='disabled')
        self.listener(stale=True)
        (self.state / 'calls').unlink()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual(outcome, 'applied', data)
        self.assertTrue(data['listener_restarted'])
        self.assertTrue((self.root / 'proc/4242/exe').samefile(self.root / 'usr/bin/php8.5'))
        self.assertEqual(self.run_program(fpm='disabled')[0], 'current')
        self.assertEqual(self.calls().count('systemctl restart orbit-private-dns.service'), 1)

    def test_never_restarts_a_listener_when_its_self_test_fails(self):
        self.listener(stale=True)
        (self.state / 'listener-invalid').touch()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual(outcome, 'listener_invalid', data)
        self.assertIn('self-test', data['reason'])
        self.assertIn('listener self-test', self.calls())
        self.assertNotIn('systemctl restart orbit-private-dns.service', self.calls())
        self.assertTrue((self.state / 'active-orbit-private-dns.service').exists())

    def test_refuses_a_listener_restart_without_its_active_socket(self):
        self.listener(stale=True)
        (self.state / 'active-orbit-private-dns.socket').unlink()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual(outcome, 'listener_invalid', data)
        self.assertIn('socket', data['reason'])
        self.assertNotIn('systemctl restart orbit-private-dns.service', self.calls())

    def test_recovers_the_listener_after_restoring_php_when_the_new_build_cannot_start_it(self):
        for name in self.pins:
            self.install(name, '8.5.11-1')
        self.listener()
        (self.state / 'breaks-8.5.12-1').touch()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual((outcome, data.get('restored')), ('listener_invalid', True), data)
        for name in self.pins:
            self.assertEqual((self.state / 'installed' / name).read_text().strip(), '8.5.11-1')
        self.assertTrue((self.state / 'active-orbit-private-dns.service').exists())
        self.assertTrue((self.root / 'proc/4242/exe').samefile(self.root / 'usr/bin/php8.5'))
        calls = self.calls()
        self.assertEqual(calls.count('systemctl restart orbit-private-dns.service'), 2)
        self.assertEqual(calls.count('listener self-test'), 2)
        for index, call in enumerate(calls):
            if call == 'systemctl restart orbit-private-dns.service':
                self.assertEqual(calls[index - 1], 'listener self-test')
        self.assertNotIn('systemctl restart orbit-private-dns.socket', calls)
        self.assertNotIn('systemctl stop orbit-private-dns.socket', calls)

    def test_reports_failed_rollback_when_the_restored_listener_still_cannot_start(self):
        for name in self.pins:
            self.install(name, '8.5.11-1')
        self.listener()
        (self.state / 'fail-listener-restart').touch()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual(outcome, 'rollback_failed', data)
        self.assertNotIn('restored', data)
        self.assertFalse((self.state / 'active-orbit-private-dns.service').exists())
        self.assertEqual(self.calls().count('systemctl restart orbit-private-dns.service'), 2)

    def test_never_restarts_on_rollback_when_the_restored_listener_self_test_fails(self):
        for name in self.pins:
            self.install(name, '8.5.11-1')
        self.listener()
        (self.state / 'fail-listener-restart').touch()
        (self.state / 'break-rollback-self-test').touch()

        outcome, data = self.run_program(fpm='disabled')

        self.assertEqual(outcome, 'rollback_failed', data)
        self.assertEqual(self.calls().count('listener self-test'), 2)
        self.assertEqual(self.calls().count('systemctl restart orbit-private-dns.service'), 1)

    def test_installs_the_pinned_files_without_removal_and_keeps_every_file(self):
        outcome, data = self.run_program()

        self.assertEqual(outcome, 'applied')
        self.assertEqual(data['changed'], ['php-common', 'php8.5-cli', 'php8.5-pcov'])
        install = next(call for call in self.calls() if call.startswith('apt-get') and '.deb' in call)
        self.assertIn('--no-remove', install)
        self.assertIn('--allow-downgrades', install)
        self.assertEqual(len(self.cached()), 3)
        self.assertTrue(all((self.state / 'installed' / name).read_text().strip() == '8.5.12-1' for name in self.pins))
        # The mirror is tried before the pin's URL.
        curls = [call for call in self.calls() if call.startswith('curl')]
        self.assertTrue(curls[0].startswith('curl https://mirror.example/php/'))

    def test_cleans_root_owned_repack_trees_before_installing_as_an_unprivileged_user(self):
        sudo = shutil.which('sudo', path='/usr/bin:/bin')
        if os.geteuid() == 0 or sudo is None or subprocess.run(
                [sudo, '-n', 'true'], capture_output=True, check=False).returncode != 0:
            self.skipTest('requires an unprivileged user with passwordless sudo')
        # Keep the package tools fake, but exercise the real ownership boundary of the enrolled SSH user.
        wrapper = Path(self.environment['PATH'].split(':')[0]) / 'sudo'
        wrapper.write_text('#!/bin/bash\n[ "$1" = -n ] && shift\n'
                           'exec /usr/bin/sudo -n env PATH="$PATH" STATE="$STATE" ROOT="$ROOT" "$@"\n')
        self.addCleanup(subprocess.run, [sudo, '-n', 'rm', '-rf', '--', self.directory.name], check=True)
        work = self.state / 'work'
        work.mkdir()
        self.environment['TMPDIR'] = str(work)
        for name in self.pins:
            self.install(name, '8.5.10-1')

        outcome, data = self.run_program()

        self.assertEqual(outcome, 'applied')
        self.assertEqual(len(data['changed']), 3)
        self.assertTrue(any(call.startswith('repack ') for call in self.calls()))
        self.assertTrue(any(call.startswith('apt-get ') and '.deb' in call for call in self.calls()))
        self.assertEqual(list(work.iterdir()), [])

    def test_downloads_nothing_when_every_package_is_at_its_version(self):
        for name in self.pins:
            self.install(name, '8.5.12-1')
        (self.root / 'etc/php/8.5/cli/conf.d/20-pcov.ini').symlink_to('/mods/pcov.ini')

        outcome, data = self.run_program()

        self.assertEqual((outcome, data['changed'], data['restarted']), ('current', [], []))
        self.assertEqual(data['reloaded'], [])
        self.assertFalse([call for call in self.calls() if call.startswith(('curl', 'apt-get'))])

    def test_repairs_upload_progress_in_both_sapis_and_reloads_every_active_master_once(self):
        for name in self.pins:
            self.install(name, '8.5.12-1')
        (self.root / 'etc/php/8.5/cli/conf.d/20-pcov.ini').symlink_to('/mods/pcov.ini')
        masters = ['php8.5-fpm.service', 'orbit-shop-php8.5-fpm.service']
        for master in masters:
            (self.state / f'active-{master}').touch()
        cli = self.root / 'etc/php/8.5/cli/conf.d/99-cve-2026-109618-upload-progress.ini'
        fpm = self.root / 'etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini'
        cli.unlink()
        fpm.write_text('session.upload_progress.enabled = 1\n')

        outcome, data = self.run_program()

        self.assertEqual((outcome, data['changed'], data['restarted']), ('applied', [], []), data)
        self.assertEqual(data['reloaded'], masters)
        for path in (cli, fpm):
            self.assertEqual(path.read_text(), 'session.upload_progress.enabled = 0\n')
            self.assertEqual(path.stat().st_mode & 0o777, 0o644)
        self.assertFalse([call for call in self.calls() if call.startswith(('curl', 'apt-get', 'systemctl restart'))])
        for master in masters:
            self.assertEqual(self.calls().count(f'systemctl reload {master}'), 1)
        self.assertEqual(self.run_program()[0], 'current')
        for master in masters:
            self.assertEqual(self.calls().count(f'systemctl reload {master}'), 1)

    def test_settings_only_change_reloads_dedicated_masters_regardless_of_the_callers_scope(self):
        for name in self.pins:
            self.install(name, '8.5.12-1')
        (self.root / 'etc/php/8.5/cli/conf.d/20-pcov.ini').symlink_to('/mods/pcov.ini')
        (self.root / 'etc/php/8.5/fpm/conf.d/20-opentelemetry.ini').symlink_to('/mods/opentelemetry.ini')
        dedicated = 'orbit-shop-php8.5-fpm.service'
        (self.state / f'active-{dedicated}').touch()
        for scope in ('none', 'shared'):
            with self.subTest(fpm=scope):
                masters = [dedicated]
                if scope == 'shared':
                    (self.state / 'active-php8.5-fpm.service').touch()
                    masters.insert(0, 'php8.5-fpm.service')
                (self.root / 'etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini').unlink()
                (self.state / 'calls').unlink(missing_ok=True)

                outcome, data = self.run_program(fpm=scope, opentelemetry=True)

                self.assertEqual((outcome, data['changed'], data['restarted']), ('applied', [], []), data)
                self.assertEqual(data['reloaded'], masters)
                self.assertEqual(data['stale_masters'], [])
                self.assertEqual(data['deferred'], [])
                for master in masters:
                    self.assertEqual(self.calls().count(f'systemctl reload {master}'), 1)
                self.assertFalse([call for call in self.calls() if call.startswith(('curl', 'apt-get', 'systemctl restart'))])
                self.assertEqual(self.run_program(fpm=scope, opentelemetry=True)[0], 'current')
                for master in masters:
                    self.assertEqual(self.calls().count(f'systemctl reload {master}'), 1)

    def test_image_install_writes_the_mitigation_for_every_version_without_services(self):
        shutil.copytree(self.root / 'etc/php/8.5', self.root / 'etc/php/8.4', symlinks=True)
        for version in ('8.4', '8.5'):
            for sapi in ('cli', 'fpm'):
                (self.root / f'etc/php/{version}/{sapi}/conf.d/99-cve-2026-109618-upload-progress.ini').unlink()
        binary = self.root / 'usr/bin/php8.4'
        binary.write_text((self.root / 'usr/bin/php8.5').read_text().replace('8.5', '8.4'))
        binary.chmod(0o755)
        payload = self.payload(fpm='none')
        payload['versions']['8.4'] = payload['versions']['8.5']

        outcome, data = result('php.install-build', payload, environment=self.environment)

        self.assertEqual(outcome, 'applied', data)
        for version in ('8.4', '8.5'):
            for sapi in ('cli', 'fpm'):
                path = self.root / f'etc/php/{version}/{sapi}/conf.d/99-cve-2026-109618-upload-progress.ini'
                self.assertTrue(path.is_file(), path)
                self.assertEqual(path.read_text(), 'session.upload_progress.enabled = 0\n')
        self.assertFalse([call for call in self.calls() if call.startswith('systemctl')])

    def test_plan_does_not_write_upload_progress_files(self):
        path = self.root / 'etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini'
        path.unlink()

        self.assertEqual(self.run_program(mode='plan')[0], 'planned')

        self.assertFalse(path.exists())
        self.assertEqual(self.calls(), [])

    def test_arm64_refuses_php_before_downloads_or_changes(self):
        dpkg = Path(self.directory.name) / 'bin/dpkg'
        dpkg.write_text(dpkg.read_text().replace('echo amd64', 'echo arm64'))
        path = self.root / 'etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini'
        path.unlink()

        outcome, data = self.run_program()

        self.assertEqual((outcome, data.get('reason')), ('download_failed', 'PHP is not available for arm64'))
        self.assertFalse(path.exists())
        self.assertEqual(self.calls(), [])
        self.assertEqual(list((self.state / 'installed').iterdir()), [])

    def test_failed_mitigation_validation_restores_files_so_convergence_can_retry(self):
        self.run_program(fpm='none')
        path = self.root / 'etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini'
        path.unlink()
        (self.state / 'active-php8.5-fpm.service').touch()
        (self.state / 'fpm-invalid').touch()

        outcome, data = self.run_program()

        self.assertEqual((outcome, data.get('restored')), ('fpm_invalid', True), data)
        self.assertFalse(path.exists())
        self.assertNotIn('systemctl reload php8.5-fpm.service', self.calls())
        (self.state / 'fpm-invalid').unlink()
        self.assertEqual(self.run_program()[0], 'applied')
        self.assertIn('systemctl reload php8.5-fpm.service', self.calls())

    def test_shared_scope_defers_invalid_shared_master_and_keeps_settings_for_pool_publication(self):
        self.run_program(fpm='none')
        path = self.root / 'etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini'
        path.unlink()
        shared = 'php8.5-fpm.service'
        master = 'orbit-shop-php8.5-fpm.service'
        (self.state / f'active-{shared}').touch()
        (self.state / f'active-{master}').touch()
        # Shared FPM uses the default configuration, without --fpm-config.
        (self.state / 'fpm-invalid-').touch()

        outcome, data = self.run_program(fpm='shared')

        self.assertEqual((outcome, data.get('deferred'), data.get('reloaded')), ('applied', [shared], [master]), data)
        self.assertEqual(path.read_text(), 'session.upload_progress.enabled = 0\n')
        self.assertNotIn(f'systemctl reload {shared}', self.calls())
        self.assertEqual(self.calls().count(f'systemctl reload {master}'), 1)
        self.assertFalse([call for call in self.calls() if call.startswith('systemctl restart')])

    def test_none_scope_defers_invalid_dedicated_master_without_undoing_other_reloads(self):
        self.run_program(fpm='none')
        path = self.root / 'etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini'
        path.unlink()
        first, second = 'orbit-aaa-php8.5-fpm.service', 'orbit-bbb-php8.5-fpm.service'
        for master in (first, second):
            (self.state / f'active-{master}').touch()
        (self.state / f'fpm-invalid-{second}').touch()

        outcome, data = self.run_program(fpm='none')

        self.assertEqual((outcome, data.get('deferred'), data.get('reloaded')), ('applied', [second], [first]), data)
        self.assertEqual(path.read_text(), 'session.upload_progress.enabled = 0\n')
        self.assertEqual(self.calls().count(f'systemctl reload {first}'), 1)
        self.assertNotIn(f'systemctl reload {second}', self.calls())
        self.assertFalse([call for call in self.calls() if call.startswith('systemctl restart')])

    def test_convergence_rolls_back_settings_with_reload_after_a_later_master_fails_validation(self):
        self.run_program(fpm='none')
        path = self.root / 'etc/php/8.5/fpm/conf.d/99-cve-2026-109618-upload-progress.ini'
        path.unlink()
        first, second = 'orbit-aaa-php8.5-fpm.service', 'orbit-bbb-php8.5-fpm.service'
        for master in (first, second):
            (self.state / f'active-{master}').touch()
        (self.state / f'fpm-invalid-{second}').touch()

        outcome, data = self.run_program(fpm='all')

        self.assertEqual((outcome, data.get('restored'), data.get('unit')), ('fpm_invalid', True, second), data)
        self.assertFalse(path.exists())
        self.assertEqual(self.calls().count(f'systemctl reload {first}'), 2)
        self.assertNotIn(f'systemctl reload {second}', self.calls())
        self.assertFalse([call for call in self.calls() if call.startswith('systemctl restart')])

    def test_refuses_installed_packages_outside_the_recipe_before_any_change(self):
        self.install('php8.5-soap', '1.2.1')
        (self.root / 'etc/apt/sources.list.d/orbit-php.sources').write_text('URIs: https://packages.sury.org/php/\n')

        for mode in ('plan', 'apply'):
            outcome, data = self.run_program(mode=mode)
            self.assertEqual((outcome, data), ('extra_packages', {'packages': ['php8.5-soap']}))
        self.assertEqual(self.calls(), [])
        self.assertTrue((self.root / 'etc/apt/sources.list.d/orbit-php.sources').exists())

    def test_keeps_extra_packages_for_an_instance_step_and_purges_them_only_when_asked(self):
        self.install('php8.5-soap', '1.2.1')
        (self.state / 'extra-modules').write_text('soap\n')

        outcome, data = self.run_program(extras='keep')
        self.assertEqual((outcome, data['purged']), ('applied', []))
        self.assertTrue((self.state / 'installed/php8.5-soap').exists())

        (self.state / 'extra-modules').unlink()
        outcome, data = self.run_program(extras='purge')
        self.assertEqual((outcome, data['purged']), ('applied', ['php8.5-soap']))
        self.assertIn('purge php8.5-soap', self.calls())
        self.assertIn('repack php8.5-soap', self.calls())

    def test_plan_lists_pending_packages_and_changes_nothing(self):
        self.install('php8.5-cli', '8.5.11-1')

        outcome, data = self.run_program(mode='plan')

        self.assertEqual((outcome, data), ('planned', {'pending': ['php-common', 'php8.5-cli', 'php8.5-pcov'], 'repair': [], 'extras': [], 'unowned': []}))
        self.assertEqual(self.calls(), [])

    def test_refuses_another_php_source_and_deletes_the_retired_orbit_source(self):
        (self.root / 'etc/apt/sources.list.d/ondrej.list').write_text('deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu x main\n')

        self.assertEqual(self.run_program(), ('conflicting_source', {'sources': [str(self.root / 'etc/apt/sources.list.d/ondrej.list')]}))

        (self.root / 'etc/apt/sources.list.d/ondrej.list').unlink()
        (self.root / 'etc/apt/sources.list.d/orbit-php.sources').write_text('URIs: https://packages.sury.org/php/\n')
        (self.root / 'usr/share/keyrings/orbit-sury-php.gpg').write_text('key')
        self.assertEqual(self.run_program()[0], 'applied')
        self.assertFalse((self.root / 'etc/apt/sources.list.d/orbit-php.sources').exists())
        self.assertFalse((self.root / 'usr/share/keyrings/orbit-sury-php.gpg').exists())

    def test_refuses_a_download_with_another_digest(self):
        pin = self.pins['php8.5-cli']
        for name in os.listdir(self.state / 'served'):
            if 'php8.5-cli' in name:
                (self.state / 'served' / name).write_bytes(b'tampered\n')

        self.assertEqual(self.run_program()[0], 'download_failed')
        self.assertNotIn(f'php8.5-cli_{pin["sha256"]}.deb', self.cached())
        self.assertFalse([call for call in self.calls() if call.startswith('apt-get')])

    def test_corrupt_mirror_stops_even_when_the_original_source_has_valid_bytes(self):
        pin = self.pins['php8.5-cli']
        mirror = self.state / 'served' / ('mirror.example_php_php8.5-cli_' + pin['sha256'] + '.deb')
        mirror.write_bytes(b'corrupt bytes')

        self.assertEqual(self.run_program()[0], 'download_failed')
        self.assertNotIn('curl ' + pin['url'], self.calls())
        self.assertFalse([call for call in self.calls() if call.startswith('apt-get')])

    def test_keeps_the_previous_build_and_rolls_back_when_php_fpm_does_not_start(self):
        for name in self.pins:
            self.install(name, '8.5.11-1')
        (self.state / 'active-php8.5-fpm.service').touch()
        (self.state / 'breaks-8.5.12-1').touch()

        outcome, data = self.run_program()

        self.assertEqual((outcome, data.get('unit'), data.get('restored')), ('rolled_back', 'php8.5-fpm.service', True), data)
        self.assertEqual(self.calls().count('repack php8.5-cli'), 1)
        self.assertTrue(all((self.state / 'installed' / name).read_text().strip() == '8.5.11-1' for name in self.pins))
        reinstall = [call for call in self.calls() if call.startswith('apt-get') and '.deb' in call][-1]
        self.assertIn('--allow-downgrades', reinstall)
        self.assertEqual(len(self.cached()), 6, 'The new and the previous build both stay in the cache.')

    def unowned_extension(self, module='amqp'):
        """A setup-php style extension: an .ini and .so that no package owns, linked into the CLI and PHP-FPM."""
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        (self.root / 'usr/lib/php/20250925').mkdir(parents=True, exist_ok=True)
        ini = self.root / f'etc/php/8.5/mods-available/{module}.ini'
        ini.write_text(f'extension={module}.so\n')
        (self.root / f'usr/lib/php/20250925/{module}.so').write_text('so')
        for sapi in ('cli', 'fpm'):
            (self.root / f'etc/php/8.5/{sapi}/conf.d/20-{module}.ini').symlink_to(ini)
        return ini

    def test_purge_extras_disables_and_keeps_extensions_that_no_package_owns(self):
        self.previous_build()
        ini = self.unowned_extension()
        (self.state / 'owned').mkdir()
        # A recipe extension's own .ini is never touched, also when no package claims it here.
        (self.root / 'etc/php/8.5/mods-available/gd.ini').write_text('extension=gd.so\n')

        outcome, data = self.run_program(extras='purge')

        self.assertEqual((outcome, data['disabled']), ('applied', ['php8.5:amqp']), data)
        self.assertFalse(ini.exists())
        self.assertFalse((self.root / 'usr/lib/php/20250925/amqp.so').exists())
        for sapi in ('cli', 'fpm'):
            self.assertFalse((self.root / f'etc/php/8.5/{sapi}/conf.d/20-amqp.ini').is_symlink())
        kept = sorted(path.name for path in (self.root / 'var/cache/orbit/php-unowned-extensions').rglob('*') if path.is_file())
        self.assertEqual(kept, ['amqp.ini', 'amqp.so'])
        self.assertTrue((self.root / 'etc/php/8.5/mods-available/gd.ini').exists())

    def settings_only_ini(self):
        """Orbit's own settings file: no package owns it and it loads no extension."""
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        ini = self.root / 'etc/php/8.5/mods-available/orbit-runtime.ini'
        ini.write_text('; Orbit runtime settings\nopcache.memory_consumption=256\n')
        (self.root / 'etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini').symlink_to(ini)
        return ini

    def test_a_settings_only_ini_that_no_package_owns_is_never_an_extension(self):
        for extras in ('refuse', 'keep', 'purge'):
            with self.subTest(extras=extras):
                self.setUp()
                self.previous_build()
                ini = self.settings_only_ini()

                planned = self.run_program(mode='plan', extras=extras)
                outcome, data = self.run_program(extras=extras, fpm='none')

                self.assertEqual((planned[0], planned[1]['unowned']), ('planned', []), planned)
                self.assertIn(outcome, ('applied', 'current'), data)
                self.assertEqual(data['disabled'], [])
                self.assertTrue(ini.is_file())
                self.assertEqual(os.readlink(self.root / 'etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini'), str(ini))

    def test_names_an_unowned_extension_by_the_library_it_loads(self):
        self.previous_build()
        self.settings_only_ini()
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        (self.root / 'usr/lib/php/20250925').mkdir(parents=True, exist_ok=True)
        (self.root / 'etc/php/8.5/mods-available/setup-php-cache.ini').write_text('zend_extension=apcu.so\n')
        (self.root / 'usr/lib/php/20250925/apcu.so').write_text('so')
        # An unowned .ini that loads a recipe extension is not an extra.
        (self.root / 'etc/php/8.5/mods-available/my-gd.ini').write_text('extension=gd.so\n')

        outcome, data = self.run_program()

        self.assertEqual((outcome, data), ('unowned_extensions', {'extensions': ['php8.5:apcu']}))

    def test_maps_a_library_to_its_module_name(self):
        """opcache.so is `zend opcache` in php -m: an unowned .ini that loads the package's opcache.so is no extra."""
        self.previous_build()
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        (self.root / 'usr/lib/php/20250925').mkdir(parents=True, exist_ok=True)
        (self.root / 'etc/php/8.5/mods-available/orbit-opcache.ini').write_text('zend_extension=opcache.so\n')
        (self.root / 'usr/lib/php/20250925/opcache.so').write_text('so')
        (self.state / 'owned').mkdir()
        (self.state / 'owned' / 'opcache.so').touch()

        outcome, data = self.run_program(mode='plan', versions={'8.5': {
            'packages': list(self.pins.values()), 'extensions': ['core', 'gd', 'intl', 'pcov', 'zend opcache']}})

        self.assertEqual((outcome, data['unowned']), ('planned', []), data)

    def test_flags_a_recipe_extension_loaded_from_a_library_no_package_owns(self):
        self.previous_build()
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        (self.root / 'opt/x').mkdir(parents=True)
        (self.root / 'opt/x/gd.so').write_text('so')
        (self.root / 'etc/php/8.5/mods-available/gd-custom.ini').write_text('extension=/opt/x/gd.so\n')
        (self.state / 'owned').mkdir()

        outcome, data = self.run_program()
        purged = self.run_program(extras='purge', fpm='none')

        self.assertEqual((outcome, data), ('unowned_extensions', {'extensions': ['php8.5:gd']}))
        self.assertFalse((self.root / 'opt/x/gd.so').exists(), purged)
        self.assertFalse((self.root / 'etc/php/8.5/mods-available/gd-custom.ini').exists())

    def test_accepts_a_recipe_extension_that_resolves_to_the_package_library(self):
        self.previous_build()
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        (self.root / 'usr/lib/php/20250925').mkdir(parents=True, exist_ok=True)
        (self.root / 'usr/lib/php/20250925/gd.so').write_text('so')
        (self.root / 'etc/php/8.5/mods-available/gd-custom.ini').write_text(
            'extension=/usr/lib/php/20250925/gd.so\n')
        (self.state / 'owned').mkdir()
        (self.state / 'owned' / 'gd.so').touch()

        outcome, data = self.run_program(mode='plan')

        self.assertEqual((outcome, data['unowned']), ('planned', []), data)

    def test_refuses_unowned_extensions_before_any_change_unless_purging(self):
        self.previous_build()
        self.unowned_extension()

        outcome, data = self.run_program()

        self.assertEqual((outcome, data), ('unowned_extensions', {'extensions': ['php8.5:amqp']}))
        self.assertFalse([call for call in self.calls() if call.startswith(('apt-get', 'curl'))])
        planned = self.run_program(mode='plan')
        self.assertEqual(planned[0], 'unowned_extensions')

    def test_an_owned_extension_ini_is_left_to_the_package_rules(self):
        self.previous_build()
        self.unowned_extension()
        (self.state / 'owned').mkdir()
        for name in ('amqp.ini', 'amqp.so'):
            (self.state / 'owned' / name).touch()

        outcome, data = self.run_program(mode='plan')

        self.assertEqual((outcome, data['unowned']), ('planned', []))

    def test_a_rollback_puts_disabled_unowned_extensions_back(self):
        self.previous_build()
        ini = self.unowned_extension()
        (self.state / 'active-php8.5-fpm.service').touch()
        (self.state / 'breaks-8.5.12-1').touch()

        outcome, data = self.run_program(extras='purge')

        self.assertEqual((outcome, data.get('restored')), ('rolled_back', True), data)
        self.assertEqual(ini.read_text(), 'extension=amqp.so\n')
        self.assertTrue((self.root / 'usr/lib/php/20250925/amqp.so').is_file())
        for sapi in ('cli', 'fpm'):
            link = self.root / f'etc/php/8.5/{sapi}/conf.d/20-amqp.ini'
            self.assertEqual(os.readlink(link), str(ini))
        self.assert_previous_build()

    def previous_build(self):
        for name in ('php8.5-cli', 'php-common'):
            self.install(name, '8.5.11-1')
        self.install('dpkg-repack', '1.54build1')

    def assert_previous_build(self):
        """The packages that the change replaced are back at their old versions, and the added one is gone."""
        self.assertEqual((self.state / 'installed/php8.5-cli').read_text().strip(), '8.5.11-1')
        self.assertEqual((self.state / 'installed/php-common').read_text().strip(), '8.5.11-1')
        self.assertFalse((self.state / 'installed/php8.5-pcov').exists())

    def test_a_failed_install_removes_added_packages_and_restores_the_previous_build(self):
        self.previous_build()
        (self.state / 'fail-install').touch()

        outcome, data = self.run_program()

        self.assertEqual((outcome, data.get('restored'), data['reason']), ('install_failed', True, 'apt-get install failed'), data)
        self.assert_previous_build()

    def test_removes_an_added_package_again_when_the_rollback_reinstall_brings_it_back(self):
        self.previous_build()
        (self.state / 'extra-modules').write_text('soap\n')
        (self.state / 'revives-8.5.11-1').write_text('php8.5-pcov')

        outcome, data = self.run_program()

        self.assertEqual(data.get('restored'), True, (outcome, data))
        self.assert_previous_build()
        self.assertEqual(2, sum(call.startswith('remove ') and 'php8.5-pcov' in call for call in self.calls()))

    def test_reinstalls_a_package_at_its_pinned_version_whose_files_changed(self):
        for name in self.pins:
            self.install(name, '8.5.12-1')
        self.install('dpkg-repack', '1.54build1')
        # setup-php overwrote a file that php8.5-cli owns; php-common only has a changed conffile, which stays.
        (self.state / 'verify-php8.5-cli').write_text('??5??????   /usr/lib/php/20250925/redis.so\n')
        (self.state / 'verify-php-common').write_text('??5?????? c /etc/php/8.5/mods-available/redis.ini\n')

        planned = self.run_program(mode='plan')
        outcome, data = self.run_program(fpm='none')

        self.assertEqual(planned, ('planned', {'pending': [], 'repair': ['php8.5-cli'], 'extras': [], 'unowned': []}))
        self.assertEqual((outcome, data['changed'], data['repaired']), ('applied', [], ['php8.5-cli']), data)
        install = [call for call in self.calls() if call.startswith('apt-get') and '.deb' in call][-1]
        self.assertIn('--reinstall', install)
        self.assertIn('php8.5-cli_', install)
        self.assertNotIn('php-common_', install)
        self.assertFalse((self.state / 'verify-php8.5-cli').exists())

    def test_a_package_with_only_a_changed_conffile_or_no_change_is_current(self):
        for name in self.pins:
            self.install(name, '8.5.12-1')
        self.run_program(fpm='none')
        (self.state / 'calls').unlink(missing_ok=True)
        (self.state / 'verify-php-common').write_text('??5?????? c /etc/php/8.5/mods-available/redis.ini\n')

        outcome, data = self.run_program(fpm='none')

        self.assertEqual((outcome, data['repaired']), ('current', []), data)
        self.assertFalse([call for call in self.calls() if call.startswith(('apt-get', 'curl'))])

    def disabled_pool_build(self):
        """php8.5-cli at 8.5.11 lists the default pool as a conffile, which Orbit disabled: it is not on disk."""
        self.previous_build()
        (self.state / 'conffiles-php8.5-cli').write_text('/etc/php/8.5/fpm/pool.d/www.conf\n/etc/php/8.5/fpm/php-fpm.conf\n')
        (self.root / 'etc/php/8.5/fpm/php-fpm.conf').write_text('[global]\n')
        (self.root / 'etc/php/8.5/fpm/pool.d/www.conf.disabled').write_text('[www]\n')

    def test_repacks_a_package_whose_default_pool_conffile_was_removed_on_purpose(self):
        self.disabled_pool_build()
        (self.state / 'fail-install').touch()

        outcome, data = self.run_program(fpm='none')

        self.assertEqual((outcome, data.get('restored')), ('install_failed', True), data)
        self.assert_previous_build()
        # The rollback copy leaves out the removed conffile and keeps the one on disk.
        self.assertEqual((self.state / 'built-conffiles-php8.5-cli').read_text(), '/etc/php/8.5/fpm/php-fpm.conf\n')
        self.assertFalse((self.root / 'etc/php/8.5/fpm/pool.d/www.conf').exists())

    def test_takes_the_rollback_copy_from_the_apt_archive_before_repacking(self):
        self.previous_build()
        archives = self.root / 'var/cache/apt/archives'
        archives.mkdir(parents=True)
        for name in ('php8.5-cli', 'php-common'):
            (archives / f'{name}_8.5.11-1_amd64.deb').write_bytes(deb(name, '8.5.11-1'))
            self.installed_payload(name, '8.5.11-1', {f'usr/share/doc/{name}/copyright': name})
        # Another version of the same package in the archive is never taken.
        (archives / 'php8.5-cli_8.5.10-1_amd64.deb').write_bytes(deb('php8.5-cli', '8.5.10-1'))
        (self.state / 'fail-install').touch()

        outcome, data = self.run_program(fpm='none')

        self.assertEqual((outcome, data.get('restored')), ('install_failed', True), data)
        self.assertFalse([call for call in self.calls() if call.startswith('repack')])
        self.assert_previous_build()

    def test_refuses_before_any_change_when_the_repack_writes_no_file_despite_exit_zero(self):
        for marker in ('build-writes-nothing', 'repack-no-tree'):
            with self.subTest(marker=marker):
                self.setUp()
                self.previous_build()
                (self.state / marker).touch()

                outcome, data = self.run_program()

                self.assertEqual((outcome, data['package']), ('rollback_unavailable', 'php-common'), data)
                self.assertFalse([call for call in self.calls() if call.startswith('apt-get') and '.deb' in call])
                self.assertEqual((self.state / 'installed/php8.5-cli').read_text().strip(), '8.5.11-1')

    def test_an_install_never_brings_back_a_disabled_default_pool(self):
        self.disabled_pool_build()
        self.pin('php8.5-fpm', '8.5.12-1')
        self.install('php8.5-fpm', '8.5.11-1')
        (self.state / 'recreates-www').touch()

        outcome, data = self.run_program(fpm='none')

        self.assertEqual(outcome, 'applied', data)
        self.assertFalse((self.root / 'etc/php/8.5/fpm/pool.d/www.conf').exists())
        self.assertTrue((self.root / 'etc/php/8.5/fpm/pool.d/www.conf.disabled').exists())

    def test_an_enabled_default_pool_stays_enabled(self):
        self.previous_build()
        (self.root / 'etc/php/8.5/fpm/pool.d/www.conf').write_text('[www]\n')
        (self.state / 'recreates-www').touch()

        outcome, data = self.run_program(fpm='none')

        self.assertEqual(outcome, 'applied', data)
        self.assertTrue((self.root / 'etc/php/8.5/fpm/pool.d/www.conf').exists())

    def installed_payload(self, name, version, files, archived=None):
        """dpkg's MD5 sums of the installed package, and the payload of its .deb (or `archived`, when it differs)."""
        import hashlib as digests
        info = self.root / 'var/lib/dpkg/info'
        info.mkdir(parents=True, exist_ok=True)
        (info / f'{name}.md5sums').write_text(''.join(
            f'{digests.md5(content.encode()).hexdigest()}  {path}\n' for path, content in files.items()))
        for path, content in (archived or files).items():
            target = self.state / 'payload' / f'{name}_{version}' / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content)

    def test_a_first_install_of_php_fpm_keeps_the_pool_it_brings(self):
        self.previous_build()
        (self.state / 'recreates-www').touch()

        outcome, data = self.run_program(fpm='none')

        self.assertEqual(outcome, 'applied', data)
        self.assertTrue((self.root / 'etc/php/8.5/fpm/pool.d/www.conf').exists())

    def test_an_archive_copy_that_differs_from_the_installed_files_is_not_used(self):
        self.previous_build()
        archives = self.root / 'var/cache/apt/archives'
        archives.mkdir(parents=True)
        (archives / 'php8.5-cli_8.5.11-1_amd64.deb').write_bytes(deb('php8.5-cli', '8.5.11-1'))
        self.installed_payload('php8.5-cli', '8.5.11-1', {'usr/bin/php8.5': 'installed'},
                               archived={'usr/bin/php8.5': 'tampered'})

        outcome, data = self.run_program(mode='apply', fpm='none')

        self.assertEqual(outcome, 'applied', data)
        self.assertIn('repack php8.5-cli', self.calls())

    def test_a_cached_copy_that_does_not_match_its_digest_or_architecture_is_not_used(self):
        self.previous_build()
        cache = self.root / 'var/cache/orbit/php-packages'
        cache.mkdir(parents=True)
        # Named after another digest, so its bytes changed after it was cached.
        (cache / f'php8.5-cli_{"0" * 64}.deb').write_bytes(deb('php8.5-cli', '8.5.11-1'))
        other = 'php-common 8.5.11-1 arm64\n'.encode()
        (cache / f'php-common_{hashlib.sha256(other).hexdigest()}.deb').write_bytes(other)

        outcome, data = self.run_program(fpm='none')

        self.assertEqual(outcome, 'applied', data)
        self.assertIn('repack php8.5-cli', self.calls())
        self.assertIn('repack php-common', self.calls())

    def test_a_dpkg_ownership_lookup_that_fails_stops_the_program(self):
        self.previous_build()
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        (self.root / 'etc/php/8.5/mods-available/apcu.ini').write_text('extension=apcu.so\n')
        (self.state / 'dpkg-S-fails').touch()

        completed = execute('php.install-build', self.payload(), environment=self.environment)

        self.assertNotEqual(completed.returncode, 0)
        self.assertIn(b'database is locked', completed.stderr)
        self.assertFalse([call for call in self.calls() if call.startswith(('apt-get', 'curl'))])

    def test_reads_an_owned_path_that_holds_a_comma(self):
        self.previous_build()
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        (self.root / 'etc/php/8.5/mods-available/apcu,extra.ini').write_text('extension=/opt/none/apcu.so\n')
        (self.state / 'owned').mkdir()
        (self.state / 'owned' / 'apcu,extra.ini').touch()

        outcome, data = self.run_program(mode='plan')

        self.assertEqual((outcome, data['unowned']), ('planned', []), data)

    def test_asks_dpkg_for_ownership_once_and_logs_no_unowned_path_noise(self):
        self.previous_build()
        (self.root / 'etc/php/8.5/mods-available').mkdir(parents=True, exist_ok=True)
        for module in ('gd', 'intl', 'pcov'):
            (self.root / f'etc/php/8.5/mods-available/{module}.ini').write_text(f'extension={module}.so\n')
        (self.root / 'etc/php/8.5/mods-available/orbit-runtime.ini').write_text('opcache.enable=1\n')

        completed = execute('php.install-build', self.payload(mode='plan'), environment=self.environment)

        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual([call for call in self.calls() if call.startswith('dpkg -S')], ['dpkg -S 3'])
        self.assertNotIn(b'no path found', completed.stderr)

    def test_a_package_that_stays_at_another_version_ends_with_rollback_failed(self):
        self.previous_build()
        # Every install of php-common lands at another version, the rollback's too, so the restore check fails.
        (self.state / 'mismatch-php-common').touch()

        outcome, data = self.run_program()

        self.assertEqual((outcome, data['package']), ('rollback_failed', 'php-common'))
        self.assertNotIn('restored', data)

    def test_a_failed_purge_rolls_back(self):
        self.previous_build()
        self.install('php8.5-soap', '1.2.1')
        (self.state / 'fail-purge').touch()

        outcome, data = self.run_program(extras='purge')

        self.assertEqual((outcome, data['restored'], data['package']), ('install_failed', True, 'php8.5-soap'))
        self.assertEqual((self.state / 'installed/php8.5-cli').read_text().strip(), '8.5.11-1')

    def test_an_install_that_fails_midway_with_a_failing_rollback_ends_with_rollback_failed(self):
        self.previous_build()
        (self.state / 'partial-install').touch()

        outcome, data = self.run_program()

        self.assertEqual(outcome, 'rollback_failed')
        self.assertIn('the rollback failed too', data['reason'])
        self.assertNotIn('restored', data)

    def test_other_extensions_roll_back_and_report_the_restored_build(self):
        self.previous_build()
        (self.state / 'extra-modules').write_text('apcu\n')

        outcome, data = self.run_program()

        self.assertEqual((outcome, data['restored']), ('modules_mismatch', True))
        self.assert_previous_build()
        # The added package goes first: the previous core packages depend on the exact previous php8.5-common.
        removal = self.calls().index('remove php8.5-pcov')
        reinstall = max(i for i, call in enumerate(self.calls()) if call.startswith('apt-get') and '.deb' in call)
        self.assertLess(removal, reinstall)

    def test_never_restarts_into_a_configuration_that_fails_validation(self):
        for name in self.pins:
            self.install(name, '8.5.11-1')
        (self.state / 'active-php8.5-fpm.service').touch()
        (self.state / 'fpm-invalid').touch()

        outcome, _ = self.run_program()

        self.assertEqual(outcome, 'fpm_invalid')
        self.assertNotIn('systemctl restart php8.5-fpm.service', self.calls())
        self.assertTrue(all((self.state / 'installed' / name).read_text().strip() == '8.5.11-1' for name in self.pins))

    def test_package_updates_restart_all_active_colocated_masters(self):
        for name in self.pins:
            self.install(name, '8.5.11-1')
        masters = ['php8.5-fpm.service', 'orbit-gateway-php8.5-fpm.service']
        for master in masters:
            (self.state / f'active-{master}').touch()

        outcome, data = self.run_program(fpm='all')

        self.assertEqual(outcome, 'applied', data)
        self.assertEqual(data['changed'], ['php-common', 'php8.5-cli', 'php8.5-pcov'])
        self.assertEqual(data['restarted'], masters)
        self.assertEqual(data['stale_masters'], [])
        for master in masters:
            self.assertEqual(self.calls().count(f'systemctl restart {master}'), 1)
        self.assertTrue(all((self.state / 'installed' / name).read_text().strip() == '8.5.12-1' for name in self.pins))

    def test_places_pcov_only_where_asked_and_never_in_php_fpm(self):
        (self.root / 'etc/php/8.5/fpm/conf.d/20-pcov.ini').symlink_to('/mods/pcov.ini')

        self.assertEqual(self.run_program(pcov=False)[0], 'applied')
        self.assertFalse((self.root / 'etc/php/8.5/cli/conf.d/20-pcov.ini').is_symlink())
        self.assertFalse((self.root / 'etc/php/8.5/fpm/conf.d/20-pcov.ini').is_symlink())

        self.assertEqual(self.run_program(pcov=True)[0], 'applied')
        self.assertTrue((self.root / 'etc/php/8.5/cli/conf.d/20-pcov.ini').is_symlink())
        self.assertFalse((self.root / 'etc/php/8.5/fpm/conf.d/20-pcov.ini').is_symlink())

    def telemetry_build(self):
        self.pin('php8.5-opentelemetry', '1.2.1-2+0~20260623.18+ubuntu26.04~1.gbpdbb97a')
        return self.payload(opentelemetry=True)

    def test_app_dev_overrides_postinst_to_load_opentelemetry_only_in_fpm(self):
        payload = self.telemetry_build()
        (self.state / 'active-php8.5-fpm.service').touch()

        outcome, data = result('php.install-build', payload, environment=self.environment)

        self.assertEqual(outcome, 'applied', data)
        self.assertIn('php8.5-opentelemetry', data['changed'])
        self.assertEqual(data['restarted'], ['php8.5-fpm.service'])
        self.assertFalse((self.root / 'etc/php/8.5/cli/conf.d/20-opentelemetry.ini').is_symlink())
        self.assertTrue((self.root / 'etc/php/8.5/fpm/conf.d/20-opentelemetry.ini').is_symlink())
        self.assertIn('phpdismod -v 8.5 -s cli opentelemetry', self.calls())
        outcome, data = result('php.install-build', payload, environment=self.environment)
        self.assertEqual((outcome, data['restarted']), ('current', []))

    def test_app_dev_cli_module_set_equals_ci(self):
        self.assertEqual(self.run_program(opentelemetry=False)[0], 'applied')
        binary = str(self.root / 'usr/bin/php8.5')
        environment = {**os.environ, **self.environment}
        ci_modules = subprocess.check_output([binary, '-m'], env=environment)

        outcome, data = result('php.install-build', self.telemetry_build(), environment=self.environment)

        self.assertEqual(outcome, 'applied', data)
        self.assertEqual(subprocess.check_output([binary, '-m'], env=environment), ci_modules)
        self.assertNotIn(b'opentelemetry', ci_modules)

    def test_losing_app_dev_keeps_opentelemetry_disabled_and_converges_without_refusal(self):
        payload = self.telemetry_build()
        (self.state / 'active-php8.5-fpm.service').touch()
        self.assertEqual(result('php.install-build', payload, environment=self.environment)[0], 'applied')
        del self.pins['php8.5-opentelemetry']

        outcome, data = self.run_program(mode='plan', opentelemetry=False, pcov=False)
        self.assertEqual((outcome, data), ('planned', {'pending': [], 'repair': [], 'extras': [], 'unowned': []}))
        outcome, data = self.run_program(opentelemetry=False, pcov=False)

        self.assertEqual((outcome, data['purged'], data['restarted']), ('applied', [], ['php8.5-fpm.service']))
        self.assertTrue((self.state / 'installed/php8.5-opentelemetry').is_file())
        for sapi in ('cli', 'fpm'):
            self.assertFalse((self.root / f'etc/php/8.5/{sapi}/conf.d/20-opentelemetry.ini').is_symlink())
        outcome, data = self.run_program(opentelemetry=False, pcov=False)
        self.assertEqual((outcome, data['restarted']), ('current', []))

    def test_beast_plan_accepts_its_pinned_opentelemetry_package_without_changes(self):
        payload = self.telemetry_build()
        for name, pin in self.pins.items():
            self.install(name, pin['version'])
        for sapi in ('cli', 'fpm'):
            (self.root / f'etc/php/8.5/{sapi}/conf.d/20-opentelemetry.ini').symlink_to('/mods/opentelemetry.ini')
        pin = self.pins['php8.5-opentelemetry']
        older = {**pin, 'name': 'php8.4-opentelemetry'}
        payload['versions']['8.4'] = {'packages': [older], 'extensions': ['core']}
        self.install(older['name'], older['version'])
        payload['mode'] = 'plan'

        outcome, data = result('php.install-build', payload, environment=self.environment)

        self.assertEqual((outcome, data), ('planned', {'pending': [], 'repair': [], 'extras': [], 'unowned': []}))
        self.assertEqual(self.calls(), [])

    def test_rolls_back_when_fpm_does_not_load_the_app_dev_opentelemetry_module(self):
        payload = self.telemetry_build()
        binary = self.root / 'usr/sbin/php-fpm8.5'
        binary.write_text(binary.read_text().replace('echo "${n%.ini}"', '[ "$n" = opentelemetry.ini ] || echo "${n%.ini}"'))
        (self.state / 'active-php8.5-fpm.service').touch()

        outcome, data = result('php.install-build', payload, environment=self.environment)

        self.assertEqual((outcome, data.get('restored')), ('rolled_back', True), data)
        self.assertIn('OpenTelemetry module does not match', data['reason'])
        self.assertFalse((self.state / 'installed/php8.5-opentelemetry').exists())

    def test_other_profiles_disable_opentelemetry_in_both_sapis(self):
        for sapi in ('cli', 'fpm'):
            (self.root / f'etc/php/8.5/{sapi}/conf.d/20-opentelemetry.ini').symlink_to('/mods/opentelemetry.ini')

        outcome, data = self.run_program(opentelemetry=False)

        self.assertEqual(outcome, 'applied', data)
        for sapi in ('cli', 'fpm'):
            self.assertFalse((self.root / f'etc/php/8.5/{sapi}/conf.d/20-opentelemetry.ini').is_symlink())
        self.assertFalse((self.state / 'installed/php8.5-opentelemetry').exists())

    def test_a_repeated_run_with_the_same_pcov_placement_changes_and_restarts_nothing(self):
        (self.state / 'active-php8.5-fpm.service').touch()
        for name in self.pins:
            self.install(name, '8.5.12-1')
        self.assertEqual(self.run_program(fpm='shared')[0], 'applied')
        (self.state / 'calls').unlink()

        for _ in range(2):
            outcome, data = self.run_program(fpm='shared')
            self.assertEqual((outcome, data['restarted']), ('current', []))
        self.assertFalse([call for call in self.calls() if call.startswith(('systemctl restart', 'apt-get'))])

    def test_rolls_back_when_the_cli_loads_other_extensions(self):
        (self.state / 'extra-modules').write_text('apcu\n')

        outcome, data = self.run_program()

        self.assertEqual((outcome, data['extra'], data['missing']), ('modules_mismatch', ['apcu'], []))

    def test_pings_each_pool_and_reports_dedicated_masters_it_did_not_restart(self):
        for name in self.pins:
            self.install(name, '8.5.11-1')
        sockets = Path(tempfile.mkdtemp(dir='/tmp', prefix='orbit-fcgi-'))
        self.addCleanup(lambda: [p.unlink() for p in sockets.iterdir()] and None or sockets.rmdir())
        served = fastcgi_server(sockets / 'pool.sock')
        (self.root / 'etc/php/8.5/fpm/php-fpm.conf').write_text('[global]\ninclude=/etc/php/8.5/fpm/pool.d/*.conf\n')
        (self.root / 'etc/php/8.5/fpm/pool.d/www.conf').write_text(f'[www]\nlisten = {sockets}/pool.sock\n')
        (self.state / 'active-php8.5-fpm.service').touch()
        (self.state / 'active-orbit-shop-php8.5-fpm.service').touch()
        self.root.joinpath(str(sockets).lstrip('/')).parent.mkdir(parents=True)
        self.root.joinpath(str(sockets).lstrip('/')).symlink_to(sockets)

        outcome, data = self.run_program(fpm='shared')

        self.assertEqual(outcome, 'applied', data)
        self.assertEqual(data['restarted'], ['php8.5-fpm.service'])
        self.assertEqual(data['stale_masters'], ['orbit-shop-php8.5-fpm.service'])
        self.assertEqual(served, [1])

    def test_a_pool_that_does_not_answer_rolls_back(self):
        for name in self.pins:
            self.install(name, '8.5.11-1')
        (self.root / 'etc/php/8.5/fpm/php-fpm.conf').write_text('[www]\nlisten = /run/php/missing.sock\n')
        (self.state / 'active-php8.5-fpm.service').touch()

        outcome, data = self.run_program()

        self.assertEqual(outcome, 'rolled_back')
        self.assertIn('does not answer FastCGI', data['reason'])


def fastcgi_server(path):
    """Answers one FastCGI request with an END_REQUEST record and counts the requests."""
    served = []
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    listener.bind(str(path))
    listener.listen(1)

    def serve():
        connection, _ = listener.accept()
        connection.recv(65536)
        connection.sendall(struct.pack('!BBHHBx', 1, 3, 1, 8, 0) + b'\0' * 8)
        connection.close()
        served.append(1)
        listener.close()

    threading.Thread(target=serve, daemon=True).start()
    return served


if __name__ == '__main__':
    unittest.main()
