"""The static PHP build id names its inputs, a stale pin fails, and the craft file gets every value it needs."""
import copy
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader('static_php_build', str(ROOT / 'bin/static-php-build'))
spec = importlib.util.spec_from_loader(loader.name, loader)
build = importlib.util.module_from_spec(spec)
loader.exec_module(build)

RECIPE = json.loads((ROOT / 'apps/gateway/resources/machine-software/recipe.json').read_text())


class StaticPhpBuildTest(unittest.TestCase):
    def changed(self, change):
        recipe = copy.deepcopy(RECIPE)
        change(recipe)
        return build.definitions(recipe)['8.5']

    def test_the_shipped_pin_names_its_definition(self):
        item = build.definitions(RECIPE)['8.5']
        self.assertEqual(item['recorded'], {'build': item['build'], 'url': item['url']})
        self.assertEqual(item['tag'], f'static-php-{item["php_version"]}-{item["build"][:12]}')
        self.assertEqual(item['url'], f'https://github.com/hardimpactdev/orbit/releases/download/{item["tag"]}/{item["tag"]}-linux-x86_64.tar.gz')
        self.assertNotIn('ffi', item['extensions'])
        self.assertNotIn('pcov', item['extensions'])
        self.assertIn('pdo_pgsql', item['extensions'])

    def test_every_input_changes_the_build_id(self):
        shipped = build.definitions(RECIPE)['8.5']['build']
        changes = [
            lambda r: r['instances']['php']['versions']['8.5'].update(php_version='8.5.13'),
            lambda r: r['instances']['php'].update(frankenphp='1.13.6'),
            lambda r: r['instances']['php'].update(static_php_cli='0' * 40),
            lambda r: r['instances']['php'].update(without=['ffi', 'pcov', 'xsl']),
            lambda r: r['php']['versions']['8.5']['extensions'].remove('zip'),
        ]
        ids = {self.changed(change)['build'] for change in changes}
        self.assertEqual(len(ids), len(changes))
        self.assertNotIn(shipped, ids)
        # The pin itself is not an input: recording the published SHA-256 keeps the build.
        self.assertEqual(self.changed(lambda r: r['instances']['php']['versions']['8.5'].update(sha256='a' * 64))['build'], shipped)

    def test_a_stale_pin_fails(self):
        stale = copy.deepcopy(RECIPE)
        stale['instances']['php']['frankenphp'] = '1.13.6'
        original = build.definitions
        build.definitions = lambda recipe=None: original(stale)
        try:
            with self.assertRaisesRegex(build.BuildError, r'(?s)instances\.php\.versions\.8\.5\.build is .*instances\.php\.versions\.8\.5\.url is'):
                build.verify_pins()
        finally:
            build.definitions = original

    def test_extension_names_follow_static_php_cli(self):
        self.assertEqual(build.spc_names(['core', 'date', 'pdo_pgsql', 'standard', 'uri', 'zend opcache']), ['pdo_pgsql', 'opcache'])

    def test_the_craft_file_gets_every_value(self):
        item = build.definitions(RECIPE)['8.5']
        craft = build.craft(item, Path('/tmp/frankenphp'))
        self.assertIn(f'php-version: "{item["php_version"]}"', craft)
        self.assertIn(f'php-src:https://www.php.net/distributions/php-{item["php_version"]}.tar.xz', craft)
        self.assertIn('frankenphp:/tmp/frankenphp', craft)
        self.assertIn('sapi: "cli,frankenphp"', craft)
        self.assertNotRegex(craft, r'@[A-Z_]+@')


if __name__ == '__main__':
    unittest.main()
