"""Exercise production command-output parsers with the pinned Ansible/Jinja engine."""
import unittest
from pathlib import Path

import yaml
from ansible.parsing.dataloader import DataLoader
from ansible.plugins.loader import init_plugin_loader
from ansible.template import Templar, trust_as_template
from jinja2 import Environment, nodes

ROOT = Path(__file__).resolve().parents[1] / 'ansible'
FIXTURES = Path(__file__).parent / 'fixtures/command-output'
JINJA = Environment(extensions=['jinja2.ext.do', 'jinja2.ext.loopcontrols'])
REGEX_FILTERS = {'regex_search', 'regex_findall', 'regex_replace'}
REGEX_TESTS = {'search', 'match', 'regex'}


def has_regex(value, field):
    """Read Jinja's syntax tree; native tests and selector filters are regex parsers too."""
    conditional = field.split('[', 1)[0] in {'when', 'changed_when', 'failed_when', 'until'}
    conditional |= field.startswith(('ansible.builtin.assert.that', 'assert.that'))
    if '{{' not in value and '{%' not in value:
        if not conditional:
            return False
        value = '{{ ' + value + ' }}'
    for node in JINJA.parse(value).find_all((nodes.Filter, nodes.Test)):
        name = node.name.rsplit('.', 1)[-1]
        if isinstance(node, nodes.Test) and name in REGEX_TESTS:
            return True
        if isinstance(node, nodes.Filter):
            if name in REGEX_FILTERS:
                return True
            index = 1 if name in {'selectattr', 'rejectattr'} else 0
            if name in {'map', 'select', 'reject', 'selectattr', 'rejectattr'} and len(node.args) > index:
                argument = node.args[index]
                if (isinstance(argument, nodes.Const) and isinstance(argument.value, str)
                        and argument.value.rsplit('.', 1)[-1] in REGEX_TESTS | REGEX_FILTERS):
                    return True
    return False


def expressions(value, task='', field='', variables=None):
    """Inventory scalar expressions and their inherited play/block/task variables."""
    variables = variables or {}
    if isinstance(value, dict):
        variables = {**variables, **value.get('vars', {})}
        if 'name' in value:
            task, field = value['name'], ''
        for key, child in value.items():
            yield from expressions(child, task, f'{field}.{key}' if field else str(key), variables)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            yield from expressions(child, task, f'{field}[{index}]', variables)
    elif isinstance(value, str) and has_regex(value, field):
        yield (task, field), (value, variables)


def trusted(value):
    if isinstance(value, str):
        return trust_as_template(value)
    if isinstance(value, dict):
        return {key: trusted(child) for key, child in value.items()}
    if isinstance(value, list):
        return [trusted(child) for child in value]
    return value


def samples(value):
    if isinstance(value, dict):
        if set(value) == {'sample'}:
            return (FIXTURES / value['sample']).read_text()
        return {key: samples(child) for key, child in value.items()}
    if isinstance(value, list):
        return [samples(child) for child in value]
    return value


class CommandOutputTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        init_plugin_loader()
        cls.table = yaml.safe_load((FIXTURES / 'cases.yaml').read_text())
        cls.inventory = {}
        for file in sorted({*ROOT.rglob('*.yml'), *ROOT.rglob('*.yaml')}):
            for (task, field), expression in expressions(yaml.safe_load(file.read_text())):
                key = (str(file.relative_to(ROOT)), task, field)
                if key in cls.inventory:
                    raise AssertionError(f'Ambiguous expression selector: {key}')
                cls.inventory[key] = expression

    def assert_coverage(self, inventory, table):
        covered = {tuple(row[key] for key in ('file', 'task', 'field')) for row in table['cases']}
        excluded = {tuple(row[key] for key in ('file', 'task', 'field')) for row in table['non_command_output']}
        self.assertEqual(covered & excluded, set())
        self.assertEqual(set(inventory), covered | excluded,
                         'Add real output cases for new parsers; classify only expressions that never parse command output.')
        self.assertTrue(all(row.get('reason') for row in table['non_command_output']))

    def test_every_regex_is_exercised_or_classified_without_command_output(self):
        self.assert_coverage(self.inventory, self.table)

    def test_unclassified_native_regex_tests_fail_coverage(self):
        for expression in [
            "result.stdout is search('ready')", "result.stdout is not match('error')",
            "result.stdout is ansible.builtin.regex('ready')",
            "result.stdout_lines | select('search', 'ready') | list",
            "result.stdout_lines | reject('ansible.builtin.match', 'error') | list",
            "result.rows | selectattr('stdout', 'search', 'ready') | list",
            "result.rows | rejectattr('stdout', 'match', 'error') | list",
            "result.stdout_lines | map('regex_replace', '^', '/') | list",
        ]:
            with self.subTest(expression=expression):
                found = dict(expressions([{'name': 'New parser', 'when': expression}]))
                self.assertIn(('New parser', 'when'), found)
                with self.assertRaises(AssertionError):
                    self.assert_coverage(found, {'cases': [], 'non_command_output': []})

    def test_recorded_output_through_production_expressions(self):
        for row in self.table['cases']:
            key = tuple(row[key] for key in ('file', 'task', 'field'))
            with self.subTest(expression=key, context=row['context']):
                expression, variables = self.inventory[key]
                context = samples(self.table['contexts'][row['context']])
                templar = Templar(loader=DataLoader(), variables={**trusted(variables), **context})
                if '{{' not in expression and '{%' not in expression:
                    actual = templar.evaluate_expression(trust_as_template(expression))
                else:
                    actual = templar.template(trust_as_template(expression))
                self.assertEqual(actual, row['expected'])

    def test_dpkg_installed_held_removed_and_absent(self):
        # F14's parser now uses db:Status-Status rather than the selection abbreviation.
        tasks = yaml.safe_load((ROOT / 'tasks/task-container-host-system.yml').read_text())
        read = next(task for task in tasks if task.get('register') == 'container_incus_package')
        self.assertEqual(read['ansible.builtin.command']['argv'][3], '${db:Status-Status}')
        install = next(task for task in tasks if task.get('name') == 'Install Incus from the recipe')
        for line in (FIXTURES / 'dpkg-status.txt').read_text().splitlines() + ['orbit-absent ']:
            package, _, status = line.partition(' ')
            with self.subTest(package=package):
                templar = Templar(loader=DataLoader(), variables={'container_incus_package': {'stdout': status}})
                result = templar.evaluate_expression(trust_as_template(install['when']))
                self.assertEqual(result, package in {'orbit-removed', 'orbit-absent'})


if __name__ == '__main__':
    unittest.main()
