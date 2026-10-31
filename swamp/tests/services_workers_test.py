"""Check worker credential boundaries without touching a fleet or secret file."""
import importlib.machinery
import importlib.util
import io
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader('services_workers', str(ROOT / 'bin/configure-services-workers'))
spec = importlib.util.spec_from_loader(loader.name, loader)
worker = importlib.util.module_from_spec(spec)
loader.exec_module(worker)


class WorkerProvisioningTest(unittest.TestCase):
    def test_banner_is_not_json_and_extra_content_is_refused(self):
        self.assertEqual(worker.reply('Remote wss://swamp.orbit\n{"id": 17}\n'), {'id': 17})
        with self.assertRaises(ValueError):
            worker.reply('{"id":17}\nunexpected trailing output')

    def test_token_plaintext_only_crosses_ssh_stdin_and_never_process_spec_or_journal(self):
        sentinel = 'worker-secret-SENTINEL'
        calls = []
        created = False

        def command(argv, source=None):
            nonlocal created
            calls.append((argv, source))
            if argv[0] == 'ssh':
                return json.dumps({'token': sentinel}) if 'token reveal' in argv[-1] else ''
            if argv[0] == 'swamp':
                if argv[1:4] == ['worker', 'token', 'create']:
                    return json.dumps({'token': sentinel, 'expiresAt': '2026-11-04T00:00:00Z'})
                if argv[1:4] == ['access', 'token', 'mint']:
                    return json.dumps({'vaultRef': {'vaultName': '_token-secrets', 'secretKey': 'fixture'}})
                if argv[1:3] == ['worker', 'list']:
                    return json.dumps({'workers':[{'tokenName':'migration-sabre','status':'idle','labels':{'pool':'migration','node':'12'}}]})
                return '{}'
            if argv[1] == 'node:show':
                return json.dumps({'status': 'active', 'platform': 'linux', 'user': 'nckrtl',
                                   'wireguard_ip': '10.44.0.10', 'access': {'can_access': []}})
            if argv[1] == 'process:list':
                return json.dumps({'processes': [{'id': 900, 'name': 'migration-sabre'}] if created else []})
            if argv[1] == 'process:create':
                created = True
                return '{"id":900}'
            return '{}'

        with tempfile.TemporaryDirectory() as temp:
            journal = Path(temp) / 'changes.jsonl'
            output = io.StringIO()
            args = SimpleNamespace(token_file='/operator/credential', journal=str(journal), node=[12])
            with patch.object(worker, 'command', command), patch('sys.stdout', output):
                worker.configure(args)
            self.assertNotIn(sentinel, journal.read_text())
            self.assertNotIn(sentinel, output.getvalue())
            self.assertTrue(all(sentinel not in json.dumps(argv) for argv, _ in calls))
            transfers = [(argv, data) for argv, data in calls if data and sentinel in data]
            self.assertEqual(len(transfers), 1)
            self.assertEqual(transfers[0][0][0], 'ssh')
            entries = [json.loads(line) for line in journal.read_text().splitlines()]
            self.assertEqual([e['action'] for e in entries if e['action'] in ('access_added','enrollment_created')],
                             ['access_added', 'access_added', 'enrollment_created'])
            self.assertEqual(entries[-1]['expires_at'], '2026-11-04T00:00:00Z')
            spec = next(argv for argv, _ in calls if argv[0] == 'orbit' and argv[1] == 'process:create')
            self.assertIn('--command=auto', spec)
            self.assertIn('--runtime=systemd', spec)
            self.assertIn('--command=--network', spec)
            self.assertIn('--command=host', spec)
            self.assertFalse(any('ci-' in arg for arg in spec))

    def test_failed_mints_record_intent_without_revoking_unowned_credentials(self):
        import subprocess
        for failure in ('enrollment_timeout', 'existing_server'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as temp:
                calls = []
                def command(argv, source=None):
                    calls.append(argv)
                    if argv[0] == 'ssh':
                        return ''
                    if argv[0] == 'orbit':
                        if argv[1] == 'node:show':
                            return json.dumps({'status':'active','platform':'linux','user':'nckrtl',
                                               'wireguard_ip':'10.44.0.10','access':{'can_access':[]}})
                        if argv[1] == 'process:list':
                            return '{"processes":[]}'
                        return '{}'
                    if argv[1:4] == ['worker','token','create']:
                        if failure == 'enrollment_timeout':
                            raise subprocess.TimeoutExpired('swamp', 600)
                        return '{"token":"fixture-only","expiresAt":"2026-11-04T00:00:00Z"}'
                    if argv[1:4] == ['access','token','mint']:
                        raise RuntimeError('active token already exists')
                    return '{}'
                journal = Path(temp) / 'changes.jsonl'
                args = SimpleNamespace(token_file='/operator/credential', journal=str(journal), node=[12])
                with patch.object(worker, 'command', command), self.assertRaises((RuntimeError, subprocess.TimeoutExpired)):
                    worker.configure(args)
                self.assertFalse(any(a[1:4] == ['access','token','revoke'] for a in calls))
                entries = [json.loads(line) for line in journal.read_text().splitlines()]
                self.assertTrue(any(e['action']=='enrollment_create_attempt' for e in entries))
                self.assertTrue(any(e['action']=='setup_failed' for e in entries))
                if failure == 'enrollment_timeout':
                    self.assertFalse(any(a[1:4] == ['worker','token','revoke'] for a in calls))

    def test_capacity_update_preserves_all_other_argv_and_replaces_existing_forms(self):
        loader = importlib.machinery.SourceFileLoader('services_capacity', str(ROOT / 'bin/configure-services-worker-capacity'))
        spec = importlib.util.spec_from_loader(loader.name, loader)
        capacity = importlib.util.module_from_spec(spec)
        loader.exec_module(capacity)
        original = ['/usr/bin/env','SWAMP_SERVER_TOKEN_FILE=/private/path','/bin/swamp','serve',
                    '--dispatch-env-allow=,','--max-concurrent-scheduled-runs=32',
                    '--max-concurrent-webhook-runs','1','--trusted-hosts','swamp.orbit']
        updated = capacity.capacity_command(original)
        self.assertEqual(updated, original[:5] + original[-2:] +
                         ['--max-concurrent-scheduled-runs','1','--max-concurrent-webhook-runs','4'])
        self.assertEqual(capacity.capacity_command(updated), updated)
        with self.assertRaises(ValueError):
            capacity.capacity_command(['/bin/swamp','serve','--max-concurrent-scheduled-runs','--dispatch-env-allow=,'])


if __name__ == '__main__':
    unittest.main()
