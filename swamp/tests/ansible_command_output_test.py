"""Exercise production command-output parsers with the pinned Ansible/Jinja engine."""
import re
import unittest
from pathlib import Path

import yaml
from ansible.parsing.dataloader import DataLoader
from ansible.plugins.loader import init_plugin_loader
from ansible.template import Templar, trust_as_template

ROOT = Path(__file__).resolve().parents[1] / 'ansible'
FIXTURES = Path(__file__).parent / 'fixtures/command-output'
REGEX = re.compile(r'\bregex_(?:search|findall|replace)\b')


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
    elif isinstance(value, str) and REGEX.search(value):
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

    def test_every_regex_is_exercised_or_classified_as_input_only(self):
        covered = {tuple(row[key] for key in ('file', 'task', 'field')) for row in self.table['cases']}
        excluded = {tuple(row[key] for key in ('file', 'task', 'field')) for row in self.table['input_only']}
        self.assertEqual(covered & excluded, set())
        self.assertEqual(set(self.inventory), covered | excluded,
                         'Add real output cases for new parsers; classify only expressions that never parse output.')
        self.assertTrue(all(row.get('reason') for row in self.table['input_only']))

    def test_recorded_output_through_production_expressions(self):
        for row in self.table['cases']:
            key = tuple(row[key] for key in ('file', 'task', 'field'))
            with self.subTest(expression=key, context=row['context']):
                expression, variables = self.inventory[key]
                context = samples(self.table['contexts'][row['context']])
                templar = Templar(loader=DataLoader(), variables=trusted({**variables, **context}))
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
