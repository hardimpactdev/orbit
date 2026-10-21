"""Pinned JavaScript upgrades use the same standalone installer on Nodes and images."""
import hashlib
import json
import os
import pwd
import subprocess
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

from test_recipe_versions import recipe, image


class RecipeJavascriptTest(unittest.TestCase):
    def test_root_inspection_runs_store_executables_as_the_managed_account(self):
        with tempfile.TemporaryDirectory() as directory:
            scope = Path(directory)
            (scope / 'bin').mkdir()
            (scope / 'bin/vp').touch()
            (scope / 'bin/.vp-setup-complete').touch()
            (scope / 'config.json').write_text('{"defaultNodeVersion":"24.22.1"}')
            node = scope / 'js_runtime/node/24.22.1/bin/node'
            node.parent.mkdir(parents=True)
            node.touch()
            account = types.SimpleNamespace(pw_name='runner')
            tools = {name: {'source': 'artifact'} for name in ('node', 'vp-cli', 'bun')}
            with patch.dict(os.environ, SUDO_USER='runner'), \
                 patch.object(recipe, 'packages', return_value={}), \
                 patch.object(recipe, 'vite_scope', return_value=scope), \
                 patch.object(recipe.pwd, 'getpwnam', return_value=account), \
                 patch.object(recipe.os, 'geteuid', return_value=0), \
                 patch.object(recipe, 'run', return_value=subprocess.CompletedProcess([], 0, 'v24.22.1', '')) as command:
                recipe.inspect(tools)
            self.assertEqual(command.call_count, 3)
            for call in command.call_args_list:
                self.assertEqual(call.args[0][:4], ['sudo', '-u', 'runner', '-H'])

    def test_old_dependency_tree_does_not_block_locked_runtime_upgrade(self):
        for module in (recipe, image):
            with self.subTest(module=module.__name__), tempfile.TemporaryDirectory() as directory:
                home = Path(directory)
                scope = home / '.local/share/vite-plus'
                old = scope / 'old/node_modules/vite-plus'
                old.mkdir(parents=True)
                (old / 'package.json').write_text('{"version":"1.0.0","dependencies":{"vite":"1.0.0"}}')
                active = scope / 'node_modules/vite-plus'
                active.parent.mkdir(parents=True)
                active.symlink_to(old)
                (scope / 'bin').mkdir()
                (scope / 'bin/vp').touch()
                store = scope / 'global'
                store.mkdir()
                (store / 'keep').write_text('managed globals')
                manifest = {'version': '1.2.0', 'dependencies': {'vite': 'npm:@voidzero-dev/vite-plus-core@1.2.0'}}
                lock = json.dumps({'name': 'orbit-recipe-vite-plus', 'version': '1.2.0', 'packages': {'': {'dependencies': {'vite-plus': '1.2.0'}}}})
                tools = {'vp': {'source': 'artifact', 'version': '1.2.0', 'lock_sha256': hashlib.sha256(lock.encode()).hexdigest()},
                         'node': {'source': 'artifact', 'version': '24.22.0'}}
                account = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir=str(home), pw_name='orbit')
                safe, publish = module.safe_directory, module.publish
                commands = []

                def directory(path, uid=0, gid=0, home=home, safe=safe):
                    return home if str(path) == '/opt/orbit' else safe(path, uid, gid)

                def write(path, *args, publish=publish, **kwargs):
                    if not str(path).startswith('/usr/local/bin/'):
                        publish(path, *args, **kwargs)

                def command(argv, commands=commands, manifest=manifest, **kwargs):
                    commands.append(argv)
                    if 'ci' in argv:
                        package = Path(argv[-1]) / 'node_modules/vite-plus'
                        package.mkdir(parents=True)
                        (package / 'package.json').write_text(json.dumps(manifest))

                with patch.dict(os.environ), \
                     patch.object(module, 'safe_directory', side_effect=directory), \
                     patch.object(module, 'extract') as extract, \
                     patch.object(module, 'publish', side_effect=write), \
                     patch.object(module, 'run', side_effect=command), \
                     patch.object(module.pwd, 'getpwnam', return_value=account), \
                     patch.object(module, 'inspect', return_value={'vp': '1.0.0', 'node': '24.22.0'}), \
                     patch.object(module, 'download') as download:
                    module.install_javascript(tools, {}, home, account, lock)
                    extract.assert_not_called()
                    download.assert_not_called()

                self.assertEqual(json.loads((scope / 'recipe/vp-current/package.json').read_text()), manifest)
                self.assertEqual(active.resolve(), (scope / ('recipe/vp-runtime-' + tools['vp']['lock_sha256']) / 'node_modules/vite-plus'))
                self.assertEqual(json.loads((old / 'package.json').read_text())['version'], '1.0.0')
                self.assertEqual((store / 'keep').read_text(), 'managed globals')
                install = next(command for command in commands if 'ci' in command)
                self.assertIn('--ignore-scripts', install)

    def test_image_fetches_only_the_standalone_dispatcher_and_not_the_npm_package(self):
        with tempfile.TemporaryDirectory() as directory:
            account = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir=directory)
            tools = {'vp': {'version': '1.2.0', 'artifacts': {'amd64': {'url': 'https://example.test/vite-plus.tgz'}}},
                     'vp-cli': {'version': '1.2.0', 'artifacts': {'amd64': {'url': 'https://example.test/vp-cli.tgz'}}}}
            archive = Path(directory) / 'dispatcher.archive'
            with patch.dict(os.environ), \
                 patch.object(image, 'inspect', return_value={'vp': '1.0.0', 'vp-cli': '1.0.0'}), \
                 patch.object(image.pwd, 'getpwnam', return_value=account), \
                 patch.object(image, 'safe_directory'), \
                 patch.object(image, 'download', return_value=archive) as download, \
                 patch.object(image, 'install_javascript') as install:
                image.image_javascript({'tools': tools, 'architecture': 'amd64', 'vp_lock': 'locked runtime'})
            self.assertEqual(download.call_count, 1)
            self.assertEqual(download.call_args.args[0], tools['vp-cli'])
            self.assertEqual(install.call_args.args[1], {'vp-cli': archive})
            self.assertEqual(install.call_args.args[-1], 'locked runtime')

    def test_same_version_with_an_old_lock_is_not_current(self):
        with tempfile.TemporaryDirectory() as directory:
            scope = Path(directory)
            (scope / 'bin').mkdir()
            (scope / 'bin/vp').touch()
            lock = b'{"locked":"closure"}'
            digest = hashlib.sha256(lock).hexdigest()
            target = scope / ('recipe/vp-runtime-' + digest)
            package = target / 'node_modules/vite-plus'
            package.mkdir(parents=True)
            (package / 'package.json').write_text('{"version":"1.2.0"}')
            (target / 'package-lock.json').write_bytes(lock)
            (scope / 'node_modules').mkdir()
            (scope / 'node_modules/vite-plus').symlink_to(package)
            tools = {'vp': {'source': 'artifact', 'version': '1.2.0', 'lock_sha256': digest, 'artifacts': {}}}
            with patch.object(recipe, 'packages', return_value={}), patch.object(recipe, 'vite_scope', return_value=scope):
                self.assertEqual(recipe.inspect(tools), {'vp': '1.2.0'})
                tools['vp']['lock_sha256'] = 'a' * 64
                self.assertEqual(recipe.inspect(tools), {'vp': None})
                tools['vp']['lock_sha256'] = digest
                (target / 'package-lock.json').write_text('changed')
                self.assertEqual(recipe.inspect(tools), {'vp': None})

    def test_wrong_lock_stops_before_runtime_activation(self):
        with tempfile.TemporaryDirectory() as directory:
            account = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_name='orbit')
            with patch.object(recipe, 'run') as command:
                with self.assertRaises(recipe.RecipeFailure):
                    recipe.install_vite_runtime(Path(directory), Path(directory), {'vp': {'lock_sha256': '0' * 64}}, '{}', account)
            command.assert_not_called()


@unittest.skipUnless(os.environ.get('VP_RECIPE_TEST_ROOT'), 'Set VP_RECIPE_TEST_ROOT to a disposable /var/tmp directory for real pinned artifacts')
class RealRecipeJavascriptTest(unittest.TestCase):
    def test_standalone_upgrade_dispatches_projects_and_preserves_globals(self):
        root = Path(os.environ['VP_RECIPE_TEST_ROOT']).resolve()
        self.assertTrue(root.is_relative_to('/var/tmp'))
        with tempfile.TemporaryDirectory(dir=root, prefix='vp-recipe-') as directory:
            home = Path(directory)
            scope = home / '.local/share/vite-plus'
            old = scope / '1.0.0/node_modules/vite-plus'
            old.mkdir(parents=True)
            (old / 'package.json').write_text('{"version":"1.0.0","dependencies":{"missing":"1.0.0"}}')
            (scope / 'current').symlink_to('1.0.0')
            (scope / 'bin').mkdir()
            (scope / 'bin/vp').touch()
            store = scope / 'global'
            store.mkdir()
            (store / 'keep').write_text('managed globals')
            pins = json.loads((Path(__file__).resolve().parents[2] / 'resources/machine-software/recipe.json').read_text())['tools']
            tools = {name: pins[name] for name in ('node', 'vp-cli', 'vp', 'pnpm')}
            downloads = {name: recipe.download(pin, 'amd64', home / (name + '.archive')) for name, pin in tools.items() if name != 'vp'}
            account = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir=str(home), pw_name=pwd.getpwuid(os.getuid()).pw_name)
            safe, publish, run = recipe.safe_directory, recipe.publish, recipe.run

            def directory(path, uid=0, gid=0):
                return home if str(path) == '/opt/orbit' else safe(path, uid, gid)

            def write(path, *args, **kwargs):
                if not str(path).startswith('/usr/local/bin/'):
                    publish(path, *args, **kwargs)

            def command(argv, **kwargs):
                return run(argv[4:] if argv[0] == 'sudo' else argv, **kwargs)

            with patch.object(recipe, 'safe_directory', side_effect=directory), \
                 patch.object(recipe, 'publish', side_effect=write), \
                 patch.object(recipe, 'run', side_effect=command):
                recipe.install_javascript(tools, downloads, home, account, (Path(__file__).resolve().parents[2] / 'resources/machine-software/vite-plus-package-lock.json').read_text())

            environment = {**os.environ, 'HOME': str(home), 'VP_HOME': str(scope), 'PATH': str(scope / 'bin') + ':' + os.environ['PATH']}
            project = home / 'project'
            project.mkdir()
            (project / 'package.json').write_text(json.dumps({'name': 'recipe-standalone-proof', 'version': '1.0.0',
                'packageManager': 'pnpm@' + tools['pnpm']['version'],
                'scripts': {'proof': 'node -e "console.log(42)"'}}))
            global_package = home / 'global-package'
            global_package.mkdir()
            (global_package / 'package.json').write_text(json.dumps({'name': 'recipe-global-proof', 'version': '1.0.0', 'bin': {'recipe-global-proof': 'index.js'}}))
            (global_package / 'index.js').write_text('#!/usr/bin/env node\nconsole.log(84)\n')
            (global_package / 'index.js').chmod(0o755)
            for argv, expected in ((['vp', '--version'], tools['vp-cli']['version']), (['node', '--version'], tools['node']['version']),
                                   (['pnpm', '--version'], tools['pnpm']['version']), (['vp', 'install'], ''), (['vp', 'run', 'proof'], '42'),
                                   (['vp', 'install', '-g', str(global_package)], ''), ([str(scope / 'bin/recipe-global-proof')], '84')):
                completed = subprocess.run(argv, cwd=project, env=environment, capture_output=True, text=True, timeout=120, check=False)
                self.assertEqual(completed.returncode, 0, str(argv) + completed.stdout + completed.stderr)
                self.assertIn(expected, completed.stdout)
            self.assertEqual(json.loads((old / 'package.json').read_text())['version'], '1.0.0')
            self.assertEqual((store / 'keep').read_text(), 'managed globals')


if __name__ == '__main__':
    unittest.main()
