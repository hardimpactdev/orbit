"""Format migration, semantic selection and independent fixture edits in real Git trees."""
import contextlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

from test_ci_units import units, BUDGET
from test_ci_build_plan import plan

registry = units.registry


class RegistryTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.git('init', '-q')
        self.git('config', 'user.name', 'CI test')
        self.git('config', 'user.email', 'ci@example.test')
        # Deliberately not alphabetical: admission order matters under pressure.
        self.files = ['swamp/z.ts', 'swamp/a.ts']
        self.records = [dict(file=file, cost=100, inputs=['swamp/shared.yml'], slices=2,
                             case_costs={'later': 60, 'first': 40}) for file in self.files]
        self.population = {file: dict(cases=['later', 'first'], source_sha256='a' * 64) for file in self.files}
        self.shared = '[budget]\n' + ''.join(f'{k} = {v}\n' for k, v in BUDGET.items()) + '\n[fixtures]\ndefault = []\n'
        legacy = self.shared + ''.join('\n[[fixture]]\n' + self.record_text(row, 'fixture.') for row in self.records)
        self.write(registry.TABLE, legacy)
        self.write(registry.LEGACY_INVENTORY, json.dumps(self.population))
        for file in self.files:
            self.write(file, '// test source\n')
        self.old = self.commit()
        self.write(registry.TABLE, self.shared)
        (self.root / registry.LEGACY_INVENTORY).unlink()
        for index, row in enumerate(self.records):
            self.write_record({**row, **self.population[row['file']], 'order': 10 * (index + 1)})
        self.new = self.commit()

    @staticmethod
    def record_text(row, prefix=''):
        text = ''.join(f'{key} = {json.dumps(value)}\n' for key, value in row.items() if key != 'case_costs')
        if 'case_costs' in row:
            text += f'[{prefix}case_costs]\n' + ''.join(f'{json.dumps(k)} = {v}\n' for k, v in row['case_costs'].items())
        return text

    def write(self, path, text):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)

    def write_record(self, row):
        self.write(registry.metadata_path(row['file']), self.record_text(row))

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, text=True, stderr=subprocess.PIPE).strip()

    def commit(self):
        self.git('add', '.')
        self.git('commit', '-qm', 'metadata')
        return self.git('rev-parse', 'HEAD')

    def changes(self, base, head):
        with contextlib.chdir(self.root):
            return units.registry_changes(base, head), units.inventory_changes(base, head)

    def fast_plan(self, base, head):
        with contextlib.chdir(self.root), patch.object(sys, 'argv', ['ci-build-plan', '--base', base, '--head', head]), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            plan.main()
        return json.loads(output.getvalue())

    def test_migration_preserves_records_plans_case_order_and_admission(self):
        before = registry.read_revision(self.root, self.old)
        after = registry.load(self.root)
        self.assertEqual(before, after)
        self.assertEqual(after, registry.read_revision(self.root, self.new))
        self.assertEqual(registry.digest(*before), registry.digest(*after))
        self.assertEqual(self.changes(self.old, self.new), (([], False), []))
        self.assertEqual(self.fast_plan(self.old, self.new), plan.select([registry.TABLE], registry_checks=set()))
        config, inventory = before
        fixtures = {row['file']: {**row, **inventory[row['file']]} for row in config['fixture']}
        with patch.object(units, 'ROOT', self.root), patch.object(units, 'fixture_steps', return_value={}):
            budget, migrated, defaults = units.configuration(self.root / registry.TABLE)
        self.assertEqual(list(fixtures), list(migrated))
        # Reduced admission capacity must defer a.ts, despite its lexical priority.
        tight = {**budget, 'pr_shards': 1, 'shard_workers': 1}
        for paths in ([self.files[0]], ['swamp/shared.yml'], ['swamp/unknown'], ['docs/a.md']):
            self.assertEqual(units.plan(paths, tight, fixtures, []), units.plan(paths, tight, migrated, defaults))
        result = units.plan(['swamp/shared.yml'], tight, migrated, defaults)
        self.assertEqual(result['deferred'], ['swamp/a.ts#0/2', 'swamp/a.ts#1/2'])
        for file in self.files:
            for unit, cost in units.units_for(fixtures[file], budget):
                self.assertEqual(units.case_names(unit, fixtures), units.case_names(unit, migrated))
        self.assertEqual(units.case_names('swamp/z.ts#0/2', migrated), ['later'])

    def test_cost_input_and_population_changes_select_only_their_fixture(self):
        file = self.files[0]
        for updates, expected_population in [({'cost': 101}, []), ({'inputs': ['swamp/new.yml']}, []),
                                              ({'cases': ['renamed', 'first']}, [file]),
                                              ({'source_sha256': 'b' * 64}, [file])]:
            with self.subTest(updates=updates):
                row = {**self.records[0], **self.population[file], 'order': 10, **updates}
                self.write_record(row)
                head = self.commit()
                changed, population = self.changes(self.new, head)
                self.assertEqual(changed, ([file] if not expected_population else [], False))
                self.assertEqual(population, expected_population)
                # The move must preserve fast-check and release-input selection too.
                old_paths = [registry.TABLE] + ([registry.LEGACY_INVENTORY] if expected_population else [])
                self.assertEqual(self.fast_plan(self.new, head), plan.select(old_paths, registry_checks=set()))

    def test_population_changes_keep_release_selection_with_other_changed_paths(self):
        file = self.files[0]
        for paths in (['docs/new.md'], ['swamp/ansible/tasks/new.yml'],
                      ['docs/new.md', 'swamp/ansible/tasks/new.yml']):
            with self.subTest(paths=paths):
                self.git('checkout', '-q', self.new)
                self.write_record({**self.records[0], **self.population[file],
                                   'source_sha256': 'b' * 64, 'order': 10})
                for path in paths:
                    self.write(path, '# input\n')
                head = self.commit()
                old_paths = [registry.TABLE, registry.LEGACY_INVENTORY, *paths]
                self.assertEqual(self.fast_plan(self.new, head), plan.select(old_paths, registry_checks=set()))

    def test_formatting_is_not_a_semantic_change(self):
        path = self.root / registry.metadata_path(self.files[0])
        path.write_text(path.read_text() + '\n# estimate source\n')
        head = self.commit()
        self.assertEqual(self.changes(self.old, head), (([], False), []))
        self.assertEqual(self.fast_plan(self.new, head), plan.select([registry.TABLE], registry_checks=set()))

    def test_new_record_appends_without_a_shared_order_edit_and_removal_is_scoped(self):
        self.write_record(dict(file='swamp/0.ts', cost=10, inputs=[]))
        added = self.commit()
        self.assertEqual([r['file'] for r in registry.load(self.root)[0]['fixture']], [*self.files, 'swamp/0.ts'])
        self.assertEqual(self.changes(self.new, added), ((['swamp/0.ts'], False), []))
        (self.root / registry.metadata_path(self.files[0])).unlink()
        removed = self.commit()
        self.assertEqual(self.changes(added, removed), (([self.files[0]], False), [self.files[0]]))

    def test_shared_budget_or_order_changes_select_all(self):
        path = self.root / registry.metadata_path(self.files[1])
        path.write_text(path.read_text().replace('order = 20', 'order = 0'))
        head = self.commit()
        self.assertEqual(self.changes(self.new, head), (([], True), []))
        self.write(registry.TABLE, self.shared.replace('pr_shards = 8', 'pr_shards = 7'))
        head = self.commit()
        self.assertTrue(self.changes(self.new, head)[0][1])

    def test_unrelated_fixture_edits_merge_cleanly_and_compare_at_merge_base(self):
        self.git('checkout', '-qb', 'topic')
        a = self.root / registry.metadata_path(self.files[0])
        a.write_text(a.read_text().replace('cost = 100', 'cost = 101'))
        topic = self.commit()
        self.git('checkout', '-qb', 'main-side', self.new)
        b = self.root / registry.metadata_path(self.files[1])
        b.write_text(b.read_text().replace('cost = 100', 'cost = 102'))
        main = self.commit()
        with contextlib.chdir(self.root):
            base, _ = plan.changed_inputs(main, topic)
        self.assertEqual(self.changes(base, topic), (([self.files[0]], False), []))
        self.git('merge', '--no-edit', topic)
        records = registry.load(self.root)[0]['fixture']
        self.assertEqual([row['cost'] for row in records], [101, 102])
        self.assertEqual(self.changes(main, 'HEAD'), (([self.files[0]], False), []))

    def test_conflicting_edits_to_same_fixture_still_conflict(self):
        a = self.root / registry.metadata_path(self.files[0])
        a.write_text(a.read_text().replace('cost = 100', 'cost = 101'))
        topic = self.commit()
        self.git('checkout', '-qb', 'other', self.new)
        a.write_text(a.read_text().replace('cost = 100', 'cost = 102'))
        self.commit()
        with self.assertRaises(subprocess.CalledProcessError):
            self.git('merge', '--no-edit', topic)

    def test_misnamed_duplicates_and_mixed_formats_are_rejected(self):
        source = (self.root / registry.metadata_path(self.files[0])).read_text()
        self.write(registry.DIRECTORY + '/wrong.toml', source)
        with self.assertRaisesRegex(ValueError, 'metadata path must match'):
            registry.load(self.root)
        (self.root / registry.DIRECTORY / 'wrong.toml').unlink()
        self.write(registry.LEGACY_INVENTORY, '{}')
        with self.assertRaisesRegex(ValueError, 'Remove the combined inventory'):
            registry.load(self.root)
        mixed = self.commit()
        with self.assertRaisesRegex(ValueError, 'Current fixture metadata'):
            registry.read_revision(self.root, mixed)
        # Old data is legal only for comparisons, never as today's configuration.
        self.git('checkout', '-q', self.old)
        with self.assertRaisesRegex(ValueError, 'Remove the combined inventory'):
            registry.load(self.root)
        self.assertEqual(len(registry.read_revision(self.root, self.old)[0]['fixture']), 2)


if __name__ == '__main__':
    unittest.main()
