"""Merge-base replay uses case changes, independently of whole-file execution selection."""
import contextlib
import io
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import runner
import selection
import spec

HEADER = 'command: node:show\nchart: node/show\ntarget: app-dev\nsources: [gateway]\n'
BATCHES = 'batches:\n  fresh-fleet: {summary: Fresh fleet.}\n'
CASE = '''  - id: show
    batch: fresh-fleet
    covers: []
    summary: Show the Node.
    act:
      - cli: {run: [node:show], expect: {exit: 0}}
    expect: []
'''
PLAIN = HEADER + 'cases:\n' + CASE
FLAGGED = PLAIN.replace('    batch:', '    fails_on_base: true\n    batch:')


class ReplayTest(unittest.TestCase):
    def replay(self, before, after):
        return selection.replay_cases(before, after, BATCHES, BATCHES)

    def test_new_case_without_flag_is_not_replayed(self):
        self.assertEqual(self.replay(None, PLAIN), [])
        self.assertEqual(self.replay(PLAIN, PLAIN + CASE.replace('id: show', 'id: refused-caller')), [])

    def test_new_flagged_case_is_replayed(self):
        self.assertEqual(self.replay(None, FLAGGED), ['show'])

    def test_unchanged_flagged_case_is_not_replayed_even_when_code_or_tooling_selects_it(self):
        for kwargs in ({'code_changed': True}, {'changed_paths': ['swamp/proof/runner/runner.py']}):
            selected = selection.select(FLAGGED, FLAGGED, before_batches=BATCHES, after_batches=BATCHES, **kwargs)
            self.assertEqual(selected['only'], ['show'])
            self.assertEqual(self.replay(FLAGGED, FLAGGED), [])

    def test_changed_flagged_case_is_replayed_without_an_id_change(self):
        self.assertEqual(self.replay(FLAGGED, FLAGGED.replace('exit: 0', 'exit: 1')), ['show'])

    def test_flagged_variants_follow_the_same_case_selection(self):
        before = FLAGGED.replace('    act:', '    variants: [{name: one}, {name: two}]\n    act:')
        after = before.replace('exit: 0', 'exit: 1')
        self.assertEqual(self.replay(before, after), selection.select(before, after, before_batches=BATCHES, after_batches=BATCHES)['only'])
        self.assertEqual(self.replay(before, after), ['show[one]', 'show[two]'])

    def test_changed_fixture_replays_only_its_flagged_users(self):
        before = FLAGGED.replace('cases:', 'fixtures:\n  cli: {steps: [{cli: {run: [node:show], expect: {exit: 0}}}]}\ncases:').replace('- cli: {run: [node:show], expect: {exit: 0}}\n    expect:', '- use: cli\n    expect:')
        after = before.replace('exit: 0', 'exit: 1')
        self.assertEqual(self.replay(before, after), ['show'])

    def test_comments_do_not_replay_a_merged_flag(self):
        self.assertEqual(self.replay(FLAGGED, '# comment\n' + FLAGGED), [])

    def test_false_is_not_an_opt_in_and_non_boolean_is_refused(self):
        self.assertEqual(self.replay(None, FLAGGED.replace('true', 'false')), [])
        for value in ('"true"', '1', 'null'):
            with self.subTest(value=value), self.assertRaisesRegex(spec.SpecError, 'fails_on_base is true or false'):
                self.replay(None, FLAGGED.replace('true', value))

    def test_invalid_base_is_refused_instead_of_replaying_unchanged_cases(self):
        with self.assertRaises(spec.SpecError):
            self.replay(FLAGGED.replace('covers: []', 'unknown: []'), FLAGGED)

    def test_cli_compares_with_the_head_runner_even_without_a_base_cases_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            head, base, batches = root / 'head.yaml', root / 'absent.yaml', root / 'batches.yaml'
            head.write_text(FLAGGED)
            batches.write_text(BATCHES)
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                code = runner.main(['replay', '--file', str(head), '--batches', str(batches),
                                    '--base-file', str(base), '--base-batches', str(batches)])
            self.assertEqual((code, out.getvalue()), (0, 'show\n'))
