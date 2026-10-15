"""php.install-build against a fake Node: dpkg, apt, systemctl, and PHP are shell fakes over a temporary root."""
import hashlib
import os
import socket
import struct
import tempfile
import threading
import unittest
from pathlib import Path

from harness import result

FAKES = {
    'sudo': '[ "$1" = -n ] && shift; exec "$@"',
    'install': 'args=(); skip=0; for a in "$@"; do [ "$skip" = 1 ] && { skip=0; continue; }; case "$a" in -o|-g) skip=1;; '
               '*) args+=("$a");; esac; done; exec /usr/bin/install "${args[@]}"',
    # Installed packages live in $STATE/installed/<name>, holding the version.
    'dpkg-query': 'for f in "$STATE"/installed/*; do [ -f "$f" ] && printf "%s\\tinstall ok installed\\t%s\\n" '
                  '"$(basename "$f")" "$(cat "$f")"; done; exit 0',
    # A path is owned by a package when $STATE/owned/<file name> exists.
    'dpkg': 'case "$1" in --print-architecture) echo amd64;; -S) [ "$2" = -- ] && shift; '
            '[ -f "$STATE/owned/$(basename "$2")" ] && echo "php8.5-common: $2" || exit 1;; '
            # dpkg --verify prints $STATE/verify-<package>, the lines of files that differ from the package.
            '--verify) shift; [ "$1" = -- ] && shift; for p in "$@"; do cat "$STATE/verify-$p" 2>/dev/null; done;; --purge|--remove) op="${1#--}"; shift; [ "$1" = -- ] && shift; '
            'echo "$op $*" >> "$STATE/calls"; [ -f "$STATE/fail-$op" ] && exit 1; '
            'for p in "$@"; do rm -f "$STATE/installed/$p"; done;; esac',
    # A fake .deb is a text file: "<name> <version> <architecture>".
    'dpkg-deb': 'read -r n v a < "$2"; printf "Package: %s\\nVersion: %s\\nArchitecture: %s\\n" "$n" "$v" "$a"',
    'curl': 'out=""; for a in "$@"; do [ "$prev" = --output ] && out="$a"; prev="$a"; done; url="${@: -1}"; '
            'echo "curl $url" >> "$STATE/calls"; src="$STATE/served/$(echo "$url" | sed "s|^https://||; s|/|_|g")"; '
            '[ -f "$src" ] || exit 22; cp "$src" "$out"',
    'apt-get': 'echo "apt-get $*" >> "$STATE/calls"; [ -f "$STATE/fail-every-install" ] && exit 100; '
               '[ -f "$STATE/fail-install" ] && { rm "$STATE/fail-install"; exit 100; }; '
               'for a in "$@"; do case "$a" in *.deb) read -r n v x < "$a"; '
               '[ -f "$STATE/mismatch-$n" ] && v=9.9; echo "$v" > "$STATE/installed/$n"; rm -f "$STATE/verify-$n"; '
               # A rollback reinstall that brings an added package back as a dependency.
               '[ -f "$STATE/revives-$v" ] && echo 8.5.12-1 > "$STATE/installed/$(cat "$STATE/revives-$v")"; '
               'if [ -f "$STATE/partial-install" ]; then rm "$STATE/partial-install"; touch "$STATE/fail-every-install"; exit 100; fi; '
               'if [ -f "$STATE/breaks-$v" ]; then touch "$STATE/broken"; else rm -f "$STATE/broken"; fi;; '
               'dpkg-repack=*) echo "${a#*=}" > "$STATE/installed/dpkg-repack";; esac; done; exit 0',
    'dpkg-repack': 'shift; echo "repack $1" >> "$STATE/calls"; printf "%s %s amd64\\n" "$1" "$(cat "$STATE/installed/$1")" '
                   '> "$1_$(cat "$STATE/installed/$1")_amd64.deb"',
    'phpenmod': 'echo "phpenmod $*" >> "$STATE/calls"; ln -sfn /mods/$5.ini "$ROOT/etc/php/$2/$4/conf.d/20-$5.ini"',
    'phpdismod': 'echo "phpdismod $*" >> "$STATE/calls"; rm -f "$ROOT/etc/php/$2/$4/conf.d/20-$5.ini"',
    'systemctl': 'echo "systemctl $*" >> "$STATE/calls"; case "$1" in '
                 'is-active) [ -f "$STATE/active-$3" ];; '
                 'restart) [ -f "$STATE/broken" ] && { rm -f "$STATE/active-$2"; exit 1; }; touch "$STATE/active-$2";; '
                 'list-units) for f in "$STATE"/active-orbit-*; do [ -f "$f" ] && echo "${f##*/active-} loaded active running"; done;; '
                 'show) echo "{ path=/usr/sbin/php-fpm8.5 ; argv[]=php-fpm8.5 --nodaemonize --fpm-config /etc/orbit/$3/php-fpm.conf }";; '
                 'esac',
}
# The CLI prints the modules of the links in its conf.d plus core; PHP-FPM does the same for its own conf.d.
PHP = ('#!/usr/bin/env bash\n[ "$1" = -t ] && { [ -f "$STATE/fpm-invalid" ] && exit 78; exit 0; }\n'
       'echo "[PHP Modules]"; echo Core; for f in "$ROOT/etc/php/8.5/{sapi}/conf.d"/*.ini; do [ -e "$f" ] || [ -L "$f" ] '
       '|| continue; n="${f##*/20-}"; echo "${n%.ini}"; done; cat "$STATE/extra-modules" 2>/dev/null; true\n')


def deb(name, version):
    return f'{name} {version} amd64\n'.encode()


class InstallBuildTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name) / 'root'
        self.state = Path(self.directory.name) / 'state'
        bin_directory = Path(self.directory.name) / 'bin'
        for path in (self.state / 'installed', self.state / 'served', bin_directory,
                     self.root / 'etc/apt/sources.list.d', self.root / 'usr/share/keyrings', self.root / 'usr/sbin',
                     self.root / 'usr/bin', self.root / 'etc/php/8.5/cli/conf.d', self.root / 'etc/php/8.5/fpm/conf.d',
                     self.root / 'etc/php/8.5/fpm/pool.d'):
            path.mkdir(parents=True)
        for name, body in FAKES.items():
            (bin_directory / name).write_text(f'#!/usr/bin/env bash\n{body}\n')
            (bin_directory / name).chmod(0o755)
        for binary, sapi in (('usr/bin/php8.5', 'cli'), ('usr/sbin/php-fpm8.5', 'fpm')):
            # PHP-FPM also loads the extensions that Orbit requires of every pool.
            required = '' if sapi == 'cli' else 'printf "%s\\n" bcmath curl gd imagick intl mbstring mysqli pdo_mysql ' \
                'pdo_pgsql pdo_sqlite redis simplexml xml zip\n'
            (self.root / binary).write_text(PHP.replace('{sapi}', sapi).replace('true\n', required + 'true\n'))
            (self.root / binary).chmod(0o755)
        for module in ('gd', 'intl'):
            for sapi in ('cli', 'fpm'):
                (self.root / f'etc/php/8.5/{sapi}/conf.d/20-{module}.ini').symlink_to(f'/mods/{module}.ini')
        self.environment = {'PATH': f'{bin_directory}:{os.environ["PATH"]}', 'STATE': str(self.state),
                            'ROOT': str(self.root)}
        self.pins = {}
        for name in ('php8.5-cli', 'php8.5-pcov', 'php-common'):
            self.pin(name, '8.5.12-1')

    def pin(self, name, version):
        content = deb(name, version)
        digest = hashlib.sha256(content).hexdigest()
        url = f'https://packages.sury.org/php/pool/{name}_{version}_amd64.deb'
        (self.state / 'served' / url.removeprefix('https://').replace('/', '_')).write_bytes(content)
        self.pins[name] = {'name': name, 'version': version, 'architecture': 'amd64', 'url': url, 'sha256': digest}

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

    def test_downloads_nothing_when_every_package_is_at_its_version(self):
        for name in self.pins:
            self.install(name, '8.5.12-1')
        (self.root / 'etc/php/8.5/cli/conf.d/20-pcov.ini').symlink_to('/mods/pcov.ini')

        outcome, data = self.run_program()

        self.assertEqual((outcome, data['changed'], data['restarted']), ('current', [], []))
        self.assertFalse([call for call in self.calls() if call.startswith(('curl', 'apt-get'))])

    def test_refuses_installed_packages_outside_the_recipe_before_any_change(self):
        self.install('php8.5-opentelemetry', '1.2.1')
        (self.root / 'etc/apt/sources.list.d/orbit-php.sources').write_text('URIs: https://packages.sury.org/php/\n')

        for mode in ('plan', 'apply'):
            outcome, data = self.run_program(mode=mode)
            self.assertEqual((outcome, data), ('extra_packages', {'packages': ['php8.5-opentelemetry']}))
        self.assertEqual(self.calls(), [])
        self.assertTrue((self.root / 'etc/apt/sources.list.d/orbit-php.sources').exists())

    def test_keeps_extra_packages_for_an_instance_step_and_purges_them_only_when_asked(self):
        self.install('php8.5-opentelemetry', '1.2.1')
        (self.state / 'extra-modules').write_text('opentelemetry\n')

        outcome, data = self.run_program(extras='keep')
        self.assertEqual((outcome, data['purged']), ('applied', []))
        self.assertTrue((self.state / 'installed/php8.5-opentelemetry').exists())

        (self.state / 'extra-modules').unlink()
        outcome, data = self.run_program(extras='purge')
        self.assertEqual((outcome, data['purged']), ('applied', ['php8.5-opentelemetry']))
        self.assertIn('purge php8.5-opentelemetry', self.calls())
        self.assertIn('repack php8.5-opentelemetry', self.calls())

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

    def test_a_package_that_stays_at_another_version_ends_with_rollback_failed(self):
        self.previous_build()
        # Every install of php-common lands at another version, the rollback's too, so the restore check fails.
        (self.state / 'mismatch-php-common').touch()

        outcome, data = self.run_program()

        self.assertEqual((outcome, data['package']), ('rollback_failed', 'php-common'))
        self.assertNotIn('restored', data)

    def test_a_failed_purge_rolls_back(self):
        self.previous_build()
        self.install('php8.5-opentelemetry', '1.2.1')
        (self.state / 'fail-purge').touch()

        outcome, data = self.run_program(extras='purge')

        self.assertEqual((outcome, data['restored'], data['package']), ('install_failed', True, 'php8.5-opentelemetry'))
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

    def test_places_pcov_only_where_asked_and_never_in_php_fpm(self):
        (self.root / 'etc/php/8.5/fpm/conf.d/20-pcov.ini').symlink_to('/mods/pcov.ini')

        self.assertEqual(self.run_program(pcov=False)[0], 'applied')
        self.assertFalse((self.root / 'etc/php/8.5/cli/conf.d/20-pcov.ini').is_symlink())
        self.assertFalse((self.root / 'etc/php/8.5/fpm/conf.d/20-pcov.ini').is_symlink())

        self.assertEqual(self.run_program(pcov=True)[0], 'applied')
        self.assertTrue((self.root / 'etc/php/8.5/cli/conf.d/20-pcov.ini').is_symlink())
        self.assertFalse((self.root / 'etc/php/8.5/fpm/conf.d/20-pcov.ini').is_symlink())

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
