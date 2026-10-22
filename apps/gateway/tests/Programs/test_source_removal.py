"""The development source removal programs, run directly against temporary checkouts. Each refusal leaves the source
in place. See docs/reference/instance-removal.md."""
import base64
import grp
import hashlib
import os
import pwd
import shutil
import signal
import subprocess
import tempfile
import time
import unittest
import uuid

from harness import execute, library, result

USER = pwd.getpwuid(os.geteuid()).pw_name
GROUP = grp.getgrgid(os.getegid()).gr_name
ORIGIN = 'ssh://git@example.test/acme/site.git'
IDENTITY = 'example.test/acme/site'


def git(*arguments, cwd=None):
    return subprocess.run(['git', *arguments], cwd=cwd, check=True, capture_output=True, text=True).stdout.strip()


class Sandbox(unittest.TestCase):
    """An apps root with one Project directory, a bare origin, and a Git config that sends the Project origin there."""

    @classmethod
    def setUpClass(cls):
        directory = tempfile.TemporaryDirectory(prefix='orbit-source-fixture-')
        cls.addClassCleanup(directory.cleanup)
        cls.origin_fixture = f'{directory.name}/origin.git'
        git('init', '--quiet', '--bare', '--initial-branch=main', cls.origin_fixture)
        seed = f'{directory.name}/seed'
        git('clone', '--quiet', cls.origin_fixture, seed)
        git('-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test',
            'commit', '--quiet', '--allow-empty', '-m', 'Initial', cwd=seed)
        git('push', '--quiet', 'origin', 'HEAD:main', cwd=seed)

    def setUp(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='orbit-remote-programs-'))
        self.addCleanup(shutil.rmtree, self.base, ignore_errors=True)
        self.root = f'{self.base}/apps'
        self.project = f'{self.root}/acme'
        os.makedirs(self.project)
        self.origin = f'{self.base}/origin.git'
        shutil.copytree(self.origin_fixture, self.origin)
        config = f'{self.base}/gitconfig'
        with open(config, 'w') as handle:
            handle.write(f'[url "{self.origin}"]\n\tinsteadOf = {ORIGIN}\n[user]\n\tname = Orbit\n\temail = orbit@example.test\n')
        self.environment = {'GIT_CONFIG_GLOBAL': config, 'GIT_CONFIG_NOSYSTEM': '1'}
        self.saved = {name: os.environ.get(name) for name in self.environment}
        os.environ.update(self.environment)
        self.addCleanup(self.restore)

    def restore(self):
        for name, value in self.saved.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value

    def checkout(self, name='dev'):
        path = f'{self.project}/{name}'
        git('clone', '--quiet', ORIGIN, path)
        git('checkout', '--quiet', '-b', name, cwd=path)
        return path

    def identity(self, path):
        status = os.lstat(path)
        return f'{status.st_dev}:{status.st_ino}'

    def run_program(self, name, payload, **options):
        return result(name, payload, environment=self.environment, **options)

    def interrupt(self, name, payload, replace):
        """Run the program with an interruption injected, as a lost connection would leave the Node."""
        completed = execute(name, {'git_read': {}, **payload}, environment=self.environment, replace=replace)
        self.assertEqual(completed.returncode, 74, completed.stderr.decode())


class ProductionRouteInspectionTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='orbit-production-route-inspection-')
        self.addCleanup(self.directory.cleanup)
        self.home = os.path.realpath(self.directory.name)
        self.release = f'{self.home}/releases/one'
        os.makedirs(self.release)
        os.symlink(self.release, f'{self.home}/current')
        with open(f'{self.release}/artisan', 'w') as handle:
            handle.write('<?php file_put_contents("booted", "yes"); echo "[]";')
        self.payload = {'inspection': 'laravel_routes', 'prefixes': ['/app', '/apps'], 'home': self.home,
                        'application': f'{self.home}/current', 'php': shutil.which('php')}

    def test_refuses_a_current_link_outside_releases_without_booting_the_application(self):
        os.unlink(f'{self.home}/current')
        os.symlink(self.home, f'{self.home}/current')

        outcome, data = result('instances.inspect-source', self.payload)

        self.assertEqual((outcome, data), ('path_mismatch', {}))
        self.assertFalse(os.path.exists(f'{self.release}/booted'))

    def test_refuses_an_application_outside_the_selected_release_without_booting_it(self):
        outcome, data = result('instances.inspect-source', {**self.payload, 'application': self.home})

        self.assertEqual((outcome, data), ('path_mismatch', {}))
        self.assertFalse(os.path.exists(f'{self.release}/booted'))

    def test_checks_the_selected_path_prefix_without_reserving_a_sibling(self):
        for uri, collision in [('powersync/sync/stream', True), ('PowerSync/write-checkpoint2.json', True),
                               ('powersync-other', False), ('app/id', False), ('{page?}', True),
                               ('{fallbackPlaceholder}', False)]:
            with self.subTest(uri=uri):
                with open(f'{self.release}/artisan', 'w') as handle:
                    handle.write('<?php echo json_encode([["uri" => "' + uri + '"]]);')
                outcome, data = result('instances.inspect-source', {**self.payload, 'prefixes': ['/powersync']})
                self.assertEqual((outcome, data), ('routes_inspected', {'collision': collision}))

    def test_suppresses_failed_artisan_output(self):
        with open(f'{self.release}/artisan', 'w') as handle:
            handle.write('<?php fwrite(STDOUT, "sensitive output"); fwrite(STDERR, "sensitive error"); exit(1);')

        completed = execute('instances.inspect-source', self.payload)

        self.assertEqual(completed.returncode, 0)
        self.assertEqual(completed.stdout, b'{"outcome":"failed","data":{}}\n')
        self.assertNotIn(b'sensitive', completed.stderr)


class InspectSourceTest(Sandbox):
    def inspect(self, checkout, **overrides):
        payload = {
            'checkout': checkout, 'root': self.root, 'user': USER, 'group': GROUP, 'layout': 'checkout',
            'branch': 'dev', 'inspect_content': True, 'unresolved': False, 'allow_absent': False, 'origin': ORIGIN,
            'prepare_id': '',
        }
        return self.run_program('instances.inspect-source', {**payload, **overrides})

    def test_reports_the_checkout_evidence(self):
        checkout = self.checkout()

        outcome, data = self.inspect(checkout)

        evidence = {key: base64.b64decode(value) for key, value in data.items()}
        self.assertEqual(outcome, 'inspected')
        self.assertEqual(evidence['top'], checkout.encode())
        self.assertEqual(evidence['common'], f'{checkout}/.git'.encode())
        self.assertEqual(evidence['origin'], ORIGIN.encode())
        self.assertEqual(evidence['branch'], b'dev')
        self.assertEqual(evidence['commit'], git('rev-parse', 'HEAD', cwd=checkout).encode())
        self.assertEqual(evidence['dirty'], b'0')
        self.assertEqual(evidence['source_identity'], self.identity(checkout).encode())
        self.assertTrue(evidence['worktrees'].startswith(f'worktree {checkout}\0'.encode()))

    def test_names_each_refused_identity_check(self):
        checkout = self.checkout()
        decoy = f'{self.base}/decoy'
        os.makedirs(decoy)
        os.symlink(decoy, f'{self.project}/linked')

        self.assertEqual(self.inspect(f'{self.base}/outside')[0], 'path_mismatch')
        self.assertEqual(self.inspect(f'{self.project}/linked')[0], 'path_mismatch')
        self.assertEqual(self.inspect(f'{self.project}/missing')[0], 'path_mismatch')
        self.assertEqual(self.inspect(checkout, user='someone-else')[0], 'ownership_mismatch')
        self.assertEqual(self.inspect(checkout, prepare_id='another-attempt')[0], 'ownership_mismatch')
        self.assertEqual(self.inspect(checkout, layout='worktree')[0], 'layout_mismatch')
        self.assertEqual(self.inspect(checkout, branch='main')[0], 'branch_mismatch')
        git('remote', 'remove', 'origin', cwd=checkout)
        self.assertEqual(self.inspect(checkout)[0], 'origin_mismatch')
        self.assertTrue(os.path.isdir(f'{checkout}/.git'))

    def test_reports_dirty_source_only_when_asked(self):
        checkout = self.checkout()
        with open(f'{checkout}/new.txt', 'w') as handle:
            handle.write('work\n')

        self.assertEqual(base64.b64decode(self.inspect(checkout)[1]['dirty']), b'1')
        self.assertEqual(base64.b64decode(self.inspect(checkout, inspect_content=False)[1]['dirty']), b'')

    def test_accepts_the_preparation_receipt_of_this_attempt(self):
        checkout = self.checkout()
        with open(f'{checkout}/.git/orbit-source-prepare', 'w') as handle:
            handle.write(f'attempt-1:{self.identity(checkout)}\n')

        self.assertEqual(self.inspect(checkout, prepare_id='attempt-1')[0], 'inspected')

    def test_reports_an_absent_source_when_absence_is_allowed(self):
        outcome, data = self.inspect(f'{self.project}/reserved', allow_absent=True, layout='worktree')

        self.assertEqual(outcome, 'inspected')
        self.assertEqual(base64.b64decode(data['source_identity']), b'absent')
        self.assertEqual(base64.b64decode(data['worktrees']), f'worktree {self.project}/reserved\0\0'.encode())


class RemoveSourceTest(Sandbox):
    def remove(self, checkout, **overrides):
        payload = {
            'checkout': checkout, 'root': self.root, 'grouping_directory': self.project, 'user': USER, 'group': GROUP,
            'branch': 'dev', 'commit': git('rev-parse', 'HEAD', cwd=checkout), 'source_identity': self.identity(checkout),
            'repository_identity': IDENTITY, 'force': False, 'own_units': [],
        }
        return self.run_program('instances.remove-source', {**payload, **overrides})

    def test_removes_a_clean_published_checkout_and_its_empty_parent(self):
        checkout = self.checkout()

        self.assertEqual(self.remove(checkout), ('removed', {}))
        self.assertFalse(os.path.lexists(checkout))
        self.assertFalse(os.path.lexists(self.project))

    def test_keeps_a_parent_that_holds_another_checkout(self):
        checkout = self.checkout()
        self.checkout('other')

        self.assertEqual(self.remove(checkout)[0], 'removed')
        self.assertTrue(os.path.isdir(f'{self.project}/other/.git'))

    def test_refuses_dirty_or_unpublished_source_unless_forced(self):
        dirty = self.checkout()
        with open(f'{dirty}/new.txt', 'w') as handle:
            handle.write('work\n')
        unpublished = self.checkout('unpublished')
        git('commit', '--quiet', '--allow-empty', '-m', 'Local only', cwd=unpublished)

        self.assertEqual(self.remove(dirty)[0], 'unsafe_content')
        self.assertEqual(self.remove(unpublished, branch='unpublished')[0], 'unsafe_content')
        self.assertTrue(os.path.isfile(f'{dirty}/new.txt'))
        self.assertTrue(os.path.isdir(f'{unpublished}/.git'))
        self.assertEqual(self.remove(unpublished, branch='unpublished', force=True)[0], 'removed')
        self.assertFalse(os.path.lexists(unpublished))

    def test_refuses_a_source_that_changed_after_inspection(self):
        checkout = self.checkout()
        inspected = git('rev-parse', 'HEAD', cwd=checkout)
        git('commit', '--quiet', '--allow-empty', '-m', 'Later', cwd=checkout)

        self.assertEqual(self.remove(checkout, commit=inspected, force=True)[0], 'source_changed')
        self.assertEqual(self.remove(checkout, source_identity='1:1', force=True)[0], 'source_changed')
        self.assertTrue(os.path.isdir(f'{checkout}/.git'))

    def test_refuses_each_identity_mismatch_even_when_forced(self):
        checkout = self.checkout()
        refusals = {
            'path_mismatch': {'grouping_directory': self.root},
            'ownership_mismatch': {'user': 'someone-else'},
            'branch_mismatch': {'branch': 'main'},
            'origin_mismatch': {'repository_identity': 'example.test/acme/other'},
        }
        for outcome, overrides in refusals.items():
            with self.subTest(outcome):
                self.assertEqual(self.remove(checkout, force=True, **overrides)[0], outcome)
        git('remote', 'set-url', 'origin', 'ssh://git@example.test/acme/site.git\n', cwd=checkout)
        self.assertEqual(self.remove(checkout, force=True)[0], 'origin_mismatch')
        self.assertTrue(os.path.isdir(f'{checkout}/.git'))

    def test_refuses_a_checkout_with_a_live_linked_worktree_and_ignores_a_prunable_one(self):
        checkout = self.checkout()
        linked = f'{self.base}/linked'
        git('worktree', 'add', '--quiet', '-b', 'linked', linked, cwd=checkout)

        self.assertEqual(self.remove(checkout, force=True)[0], 'worktrees_mismatch')
        shutil.rmtree(linked)
        self.assertEqual(self.remove(checkout, force=True)[0], 'removed')

    def test_refuses_shared_git_administration_as_a_layout_mismatch(self):
        checkout = self.checkout()
        shared = f'{self.base}/shared.git'
        shutil.copytree(f'{checkout}/.git', shared)
        with open(f'{checkout}/.git/commondir', 'w') as handle:
            handle.write(f'{shared}\n')

        self.assertEqual(self.remove(checkout, force=True)[0], 'layout_mismatch')
        self.assertTrue(os.path.isdir(shared))


class FinalizeSourceRemovalTest(Sandbox):
    """The recorded removal: prepare the journal, finalize through quarantine, and recover from an interruption."""

    def setUp(self):
        super().setUp()
        self.operation = str(uuid.uuid4())
        self.digest = hashlib.sha256(b'accepted source').hexdigest()
        self.receipt = hashlib.sha256(b'receipt').hexdigest()
        self.quarantine = f'{self.root}/.orbit-removals/{self.operation}.7.quarantine'

    def member(self, checkout, layout='checkout', common=None):
        return {
            'checkout': checkout, 'root': self.root, 'operation': self.operation, 'member': '7',
            'digest': self.digest, 'user': USER, 'group': GROUP, 'layout': layout,
            'common_repository': common or checkout, 'source_identity': self.identity(checkout),
        }

    def prepare(self, member):
        keys = ('root', 'operation', 'member', 'digest', 'user', 'group', 'checkout', 'common_repository', 'layout', 'source_identity')
        return self.run_program('instances.prepare-source-removal', {key: member[key] for key in keys})

    def state(self, member):
        payload = {key: member[key] for key in ('checkout', 'root', 'operation', 'member', 'digest', 'source_identity', 'user', 'group')}
        return self.run_program('instances.read-source-removal-state', {**payload, 'receipt': self.receipt, 'grouping_directory': self.project})[0]

    def finalize(self, member, **options):
        return self.run_program('instances.finalize-source-removal', self.finalization(member), **options)

    def finalization(self, member):
        checkout = member['checkout']
        return {
            **member, 'branch': git('symbolic-ref', '--short', 'HEAD', cwd=checkout), 'receipt': self.receipt,
            'force': False, 'origin': ORIGIN, 'commit': git('rev-parse', 'HEAD', cwd=checkout),
            'worktrees': base64.b64encode(subprocess.run(['git', '-C', checkout, 'worktree', 'list', '--porcelain', '-z'], capture_output=True, check=True).stdout).decode(),
            'grouping_directory': self.project, 'unresolved': False, 'independent_worktree': False,
            'own_units': [],
        }

    def test_prepares_the_journal_once_and_refuses_another_digest(self):
        member = self.member(self.checkout())

        self.assertEqual(self.prepare(member), ('prepared', {}))
        self.assertEqual(self.prepare(member), ('prepared', {}))
        journal = f'{self.root}/.orbit-removals/{self.operation}.7.journal'
        with open(journal) as handle:
            self.assertEqual(handle.read(), f'{self.digest}\n')
        self.assertEqual(os.stat(journal).st_mode & 0o777, 0o600)
        self.assertEqual(os.stat(f'{self.root}/.orbit-removals').st_mode & 0o777, 0o700)
        self.assertEqual(self.prepare({**member, 'digest': '0' * 64})[0], 'refused')

    def test_finalizes_a_checkout_through_quarantine_with_a_receipt(self):
        checkout = self.checkout()
        member = self.member(checkout)
        self.prepare(member)

        self.assertEqual(self.state(member), 'present')
        self.assertEqual(self.finalize(member), ('finalized', {'receipt': self.receipt}))
        self.assertFalse(os.path.lexists(checkout))
        self.assertFalse(os.path.lexists(self.quarantine))
        self.assertFalse(os.path.lexists(self.project))
        self.assertEqual(self.state(member), 'completed')

    def test_refuses_dirty_source_or_a_replaced_directory_before_moving_it(self):
        checkout = self.checkout()
        member = self.member(checkout)
        self.prepare(member)
        with open(f'{checkout}/new.txt', 'w') as handle:
            handle.write('work\n')

        self.assertEqual(self.finalize(member)[0], 'refused')
        os.remove(f'{checkout}/new.txt')
        self.assertEqual(self.finalize({**member, 'source_identity': '1:1'})[0], 'refused')
        self.assertTrue(os.path.isdir(f'{checkout}/.git'))
        self.assertFalse(os.path.lexists(self.quarantine))

    def test_keeps_a_checkout_on_another_filesystem_in_place(self):
        checkout = self.checkout()
        member = self.member(checkout)
        self.prepare(member)

        outcome = self.finalize(member, replace={'return os.lstat(first).st_dev == os.lstat(second).st_dev': 'return False'})

        self.assertEqual(outcome, ('cross_device', {}))
        self.assertTrue(os.path.isdir(f'{checkout}/.git'))

    def test_resumes_a_checkout_interrupted_after_its_receipt(self):
        checkout = self.checkout()
        member = self.member(checkout)
        self.prepare(member)
        interrupted = {"    remove_source_tree(quarantine, common_repository if layout == 'worktree' else None, 'refused')": '    raise SystemExit(74)'}

        self.interrupt('instances.finalize-source-removal', self.finalization(member), interrupted)
        self.assertEqual(self.state(member), 'receipt_pending_cleanup')
        shutil.rmtree(f'{self.quarantine}/.git')
        receipt = {key: member[key] for key in ('checkout', 'root', 'common_repository', 'layout', 'operation', 'member', 'digest', 'user', 'group', 'source_identity')}
        receipt['receipt'] = self.receipt

        self.assertEqual(self.run_program('instances.read-removal-receipt', receipt), ('incomplete', {}))
        self.assertEqual(
            self.run_program('instances.clean-removal-receipt', {**receipt, 'grouping_directory': self.project}),
            ('cleaned', {'receipt': self.receipt}),
        )
        self.assertFalse(os.path.lexists(self.quarantine))
        self.assertEqual(self.state(member), 'completed')

    def test_finalizes_a_worktree_and_keeps_its_branch_and_repository(self):
        repository = self.checkout('default')
        worktree = f'{self.project}/feature'
        git('worktree', 'add', '--quiet', '-b', 'feature', worktree, cwd=repository)
        git('push', '--quiet', 'origin', 'feature', cwd=worktree)
        member = self.member(worktree, layout='worktree', common=repository)
        self.prepare(member)
        moved = {'physical = quarantine\n    require(is_real_directory(physical)': 'physical = quarantine\n    raise SystemExit(74)\n    require(is_real_directory(physical)'}

        self.interrupt('instances.finalize-source-removal', self.finalization(member), moved)
        self.assertEqual(self.state(member), 'quarantined')

        payload = {
            **member, 'branch': 'feature', 'receipt': self.receipt, 'force': True, 'origin': ORIGIN,
            'commit': git('rev-parse', 'feature', cwd=repository), 'worktrees': '', 'grouping_directory': self.project,
            'unresolved': False, 'independent_worktree': True, 'own_units': [],
        }
        self.assertEqual(self.run_program('instances.finalize-source-removal', payload)[0], 'finalized')
        self.assertFalse(os.path.lexists(worktree))
        self.assertFalse(os.path.lexists(self.quarantine))
        self.assertEqual(git('worktree', 'list', '--porcelain', cwd=repository).count('worktree '), 1)
        self.assertEqual(git('rev-parse', '--verify', 'refs/heads/feature', cwd=repository), payload['commit'])


class StopTreeProcessesTest(unittest.TestCase):
    """The process stop of a task workspace: the Instance's own units and a tmux or screen server stay."""

    def setUp(self):
        self.tree = os.path.realpath(tempfile.mkdtemp(prefix='orbit-own-units-'))
        self.addCleanup(shutil.rmtree, self.tree, ignore_errors=True)
        self.functions = library('lib/program.py', 'lib/source-removal.py')

    def spawn(self, command=('sleep', '300')):
        process = subprocess.Popen(list(command), cwd=self.tree, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(self.kill, process)
        return process

    def kill(self, process):
        if process.poll() is None:
            process.kill()
        process.wait()

    def stop(self, unit, own_units):
        self.functions['process_unit'] = lambda pid: unit
        return self.functions['stop_tree_processes'](self.tree, own_units)

    def test_stops_a_process_in_another_orbit_unit_such_as_the_agents_pi_server(self):
        process = self.spawn()

        self.assertTrue(self.stop('orbit-process-148-pi-server.service', ['orbit-process-7-web.service']))
        self.assertEqual(process.wait(timeout=10), -signal.SIGTERM)

    def test_leaves_the_processes_of_the_instances_own_units_running(self):
        units = ['orbit-process-7-web.service', 'orbit-schedule-0f8b1c3e-2a4d-4e6f-9a1b-3c5d7e9f1a2b.service']
        for unit in units:
            with self.subTest(unit):
                process = self.spawn()
                self.assertTrue(self.stop(unit, units))
                time.sleep(0.1)
                self.assertIsNone(process.poll())

    def test_leaves_a_tmux_server_running(self):
        # A copy of bash keeps its behaviour under any name. It waits on a FIFO without starting another process.
        shell = f'{self.tree}/tmux: server'
        shutil.copy(shutil.which('bash'), shell)
        os.mkfifo(f'{self.tree}/wait')
        process = self.spawn((shell, '-c', 'read < "$0"', f'{self.tree}/wait'))
        time.sleep(0.2)

        self.assertTrue(self.stop('', []))
        time.sleep(0.1)
        self.assertIsNone(process.poll())


if __name__ == '__main__':
    unittest.main()
