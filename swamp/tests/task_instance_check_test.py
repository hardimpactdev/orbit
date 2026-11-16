"""Real workspace proof; independent file with the shared, test-free harness."""
import json
import runpy
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))
CHECK = SUPPORT["CHECK"]
PARENT = SUPPORT["PARENT"]


class TaskInstanceCheckTest(SUPPORT["CheckHarness"]):
    def test_check_runs_in_the_guest_and_commits_the_exact_candidate(self):
        index = (self.checkout / '.git/index').read_bytes()
        self.check['checkout_path'] = str(self.decoy)
        evidence, local = self.checked()
        self.assertEqual(local['outcome'], 'passed', local)
        self.assertEqual(local['exit_code'], 0)
        self.assertIn(CHECK['expected']['project_output'], local['output'])
        self.assertEqual(local['head_before'], self.base)
        self.assertEqual(local['head'], self.base)
        self.assertEqual(local['subtask_start_commit'], self.base)
        self.assertEqual(local['tree'], CHECK['expected']['candidate_tree'])
        self.assertEqual(self.git('ls-tree', '-r', local['tree']), CHECK['expected']['ls_tree'].strip())
        self.assertEqual(self.git('show', local['tree'] + ':ignored/staged.txt'), 'staged ignored work')
        self.assertEqual([item['outcome'] for item in local['deliverables']], ['passed', 'passed'], local['deliverables'])
        feature = local['evidence']['commands']['feature']
        self.assertEqual((feature['exit_code'], feature['base_started'], feature['base_exit_code']), (0, True, 1), feature)
        self.assertEqual(local['evidence']['diff'], [{'path': 'app.txt', 'status': 'M'}, {'path': 'ignored/staged.txt', 'status': 'A'},
                                                     {'path': 'tests/feature.sh', 'status': 'A'}])
        # Output files were read, redacted and deleted; evidence carries no command output.
        self.assertEqual(list((self.folder / 'output').iterdir()), [])
        self.assertNotIn('checked new', json.dumps(evidence))
        self.assertFalse(self.scratch.exists(), 'guest check scratch leaked')
        # The Node folders this check's own run made, by the targets its Incus shim recorded; never another unit's folder.
        pulled = {json.loads(line)[-1] for line in self.calls.read_text().splitlines()
                  if json.loads(line)[2:5] == ['file', 'pull', '--recursive']}
        self.assertTrue(pulled and all(target.startswith('/var/tmp/orbit-task-check-') for target in pulled), pulled)
        self.assertEqual([target for target in pulled if Path(target).exists()], [], 'Node output scratch leaked')
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        self.assertEqual((self.decoy / 'app.txt').read_text(), 'decoy\n')
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        self.assertTrue(all(str(self.decoy) not in json.dumps(call[:19]) for call in calls))
        self.assertTrue(all(call[2:4] in (['exec', '--disable-stdin'], ['file', 'pull']) for call in calls), calls)

        candidate, finish = self.finished()
        self.assertEqual(candidate['outcome'], 'passed', candidate)
        self.assertEqual(self.git('rev-parse', candidate['commit'] + '^{tree}'), local['tree'])
        self.assertEqual(self.git('rev-parse', candidate['commit'] + '^'), self.base)
        self.assertEqual(self.git('log', '-1', '--format=%an <%ae>%n%B', candidate['commit']),
                         'Orbit <orbit@users.noreply.github.com>\n' + self.check['message'].strip())
        ref = 'refs/orbit/check-operations/' + str(CHECK['operation_id'])
        self.assertEqual(self.steps['pinned']['outputs']['evidence'], {'ref': ref, 'commit': candidate['commit'], 'changed': True})
        self.assertEqual(self.git('rev-parse', ref), candidate['commit'])
        self.assertEqual(finish['pending'], False)
        self.assertEqual(json.loads(finish['result_json']), candidate)
        self.assertEqual(self.git('status', '--porcelain=v1'), '')

    def test_only_a_clean_recorded_candidate_makes_no_commit(self):
        # The implementer committed the work. Its head is clean but no check recorded it: it gets a fresh Orbit commit with the
        # Gateway's cleaned message, so the implementer's skip directive never heads the candidate.
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Implementer work [skip ci]')
        head = self.git('rev-parse', 'HEAD')
        _, local = self.checked()
        self.assertEqual((local['outcome'], local['head']), ('passed', head), local)
        first, _ = self.finished()
        self.assertEqual(self.git('rev-parse', first['commit'] + '^'), head)
        self.assertEqual(self.git('log', '-1', '--format=%B', first['commit']).strip(), self.check['message'].strip())

        # A second check of that clean, recorded head, such as after a publish, makes no commit: a cancel then finds no work.
        self.steps = {}
        _, local = self.checked()
        self.assertEqual(local['head'], first['commit'], local)
        second, finish = self.finished(commits=False)
        self.assertEqual(second['commit'], first['commit'], second)
        self.assertEqual(self.git('rev-parse', 'HEAD'), first['commit'])
        self.assertEqual(json.loads(finish['result_json']), second)

    def test_failed_setup_keeps_output_skips_the_check_and_commits_nothing(self):
        self.check.update({'phase': 'baseline', 'base': '', 'deliverables': [], 'command_deliverables': [],
                           'setup': [{'name': 'install', 'command': "echo 'dependency missing'; exit 4", 'timeout_seconds': 60}]})
        head = self.git('rev-parse', 'HEAD')
        _, local = self.checked()
        self.assertEqual((local['outcome'], local['exit_code'], local['failed_step']), ('failed', 4, 'install'), local)
        _, finish = self.finished()
        self.assertNotIn('committed', self.steps)
        self.assertNotIn('pinned', self.steps)
        self.assertEqual(json.loads(finish['result_json']), local)
        self.assertIn('dependency missing', local['output'])
        self.assertEqual(local['subtask_start_commit'], head)
        self.assertIsNone(local['evidence'])
        steps = self.evidence('check')['check']['steps']
        self.assertNotIn('project', steps)
        self.assertFalse(self.scratch.exists())
        self.assertEqual(self.git('rev-parse', 'HEAD'), head)

    def test_a_moved_branch_refuses_before_any_command_and_leaves_no_scratch(self):
        self.git('checkout', '--quiet', '-b', 'user-branch')
        self.ran('prepare', self.behavior('task-repository/check.ts', 'prepare', {
            'intent': self.check, 'node_id': self.check['node_id'], 'root': self.cel('prepare', 'root')}))
        output = self.run_phase('check', self.write_input('input'), ok=False)
        self.assertIn('tasks.workspace_identity_changed', output)
        # The entry playbook records the refusal; the child turns it into the check's error receipt.
        (self.folder / 'result.json').write_text(json.dumps({'error_code': 'tasks.workspace_identity_changed'}))
        self.ran('checked', self.behavior('task-instance-workspace/assets.ts', 'result', {'folder': str(self.folder), 'phase': 'check'}))
        local = self.ran('local', self.behavior('task-repository/check.ts', 'local', {
            'intent': self.check, 'prepared': self.cel('local', 'prepared'), 'steps': self.cel('local', 'steps'),
            'outputs': self.cel('local', 'outputs')}))['result']
        self.assertEqual(local, {'error_code': 'tasks.workspace_identity_changed', 'check_id': self.check['check_id']})
        _, finish = self.finished()
        self.assertEqual(json.loads(finish['result_json']), local)
        self.assertNotIn('checked', output)
        self.assertFalse(self.scratch.exists())
        self.assertFalse((self.folder / 'output').exists())

    def test_task_vm_intents_keep_the_task_vm_steps(self):
        vm = {key: value for key, value in self.check.items() if key != 'workspace'}
        self.assertTrue(self.cel('Check in the Instance', 'guard', PARENT, check=vm))
        self.assertFalse(self.cel('Build every argv', 'guard', PARENT, check=vm))
        self.assertFalse(self.cel('Push the candidate', 'guard', PARENT, check=vm))
        self.assertEqual(self.cel('Push the candidate', 'workflow', PARENT, check=vm), 'task-check-push')

    def test_check_phases_need_their_check_and_candidate(self):
        intent = self.ansible_intent()
        del intent['check']
        self.assertIn('tasks.workspace_target_invalid', self.run_phase('check', intent, ok=False))
        self.assertIn('tasks.workspace_target_invalid', self.run_phase('commit-ref', self.ansible_intent(), ok=False))
        self.assertFalse(self.calls.exists(), 'an invalid check contacted the transport')


if __name__ == "__main__":
    unittest.main()
