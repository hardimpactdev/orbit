"""The normal load adds only missing manifest actions and preserves policy."""
import copy
import importlib.util
import json
import runpy
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('manifest_grants', ROOT / 'platform/swamp-load/manifest_grants.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)
RAW = json.dumps({'version': 2, 'principal': M.PRINCIPAL, 'vaults': [],
                  'workflows': [{'workflow': 'operation-example', 'actions': ['run', 'read']}]}).encode()


class Native:
    def __init__(self):
        self.grants = [{'id': 'other', 'subject': {'kind': 'user', 'name': 'other'}, 'actions': ['run']}]
        self.groups = [{'name': 'prior', 'members': ['user:other']}]
        self.calls, self.fail = [], None

    def __call__(self, args):
        self.calls.append(args)
        if args == ['access', 'grant', 'list']:
            return copy.deepcopy(self.grants)
        if args == ['access', 'group', 'list']:
            return copy.deepcopy(self.groups)
        if args[:3] == ['access', 'grant', 'create']:
            kind, pattern = args[args.index('--on') + 1].split(':', 1)
            grant = {'subject': M.SUBJECT, 'effect': 'allow', 'id': 'new',
                     'resource': {'kind': kind, 'pattern': pattern},
                     'actions': args[args.index('--allow') + 1].split(',')}
            if '--when' in args:
                grant['condition'] = args[args.index('--when') + 1]
            self.grants.append(grant)
            if self.fail == 'create':
                raise TimeoutError('accepted_create_reply_lost')
            return {'status': 'succeeded'}
        if args == ['access', 'reload']:
            if self.fail == 'reload':
                raise TimeoutError('reload_reply_lost')
            return {'success': True}
        raise AssertionError(args)


class ManifestGrants(unittest.TestCase):
    def test_partial_scope_adds_only_missing_read_and_preserves_prior_policy(self):
        with tempfile.TemporaryDirectory() as tmp:
            native = Native()
            native.grants.append({**M.scopes(json.loads(RAW))[0], 'id': 'partial', 'actions': ['run']})
            before = copy.deepcopy(native.grants)
            result = M.reconcile(RAW, 'a' * 40, Path(tmp), native)
            self.assertEqual([['read']], [r['actions'] for r in result['created']])
            self.assertTrue(all(g in native.grants for g in before))
            self.assertEqual(before, json.loads(Path(result['backup']).read_text())['grants'])
            self.assertEqual('applied', result['state'])
            native.calls = []
            self.assertTrue(M.reconcile(RAW, 'a' * 40, Path(tmp), native)['read_only_recheck'])
            self.assertFalse(any('create' in c or 'reload' in c for c in native.calls))

    def test_vault_grant_keeps_the_exact_key_condition(self):
        with tempfile.TemporaryDirectory() as tmp:
            policy = json.loads(RAW)
            policy['vaults'] = [{'vault': 'orbit-inputs', 'key_pattern': '^owned-[0-9]+$',
                                 'actions': ['write', 'read']}]
            native = Native()
            out = M.reconcile(json.dumps(policy).encode(), 'a' * 40, Path(tmp), native)
            self.assertEqual("key.matches('^owned-[0-9]+$')", out['created'][1]['condition'])
            self.assertEqual(['write', 'read'], out['created'][1]['actions'])

    def test_conflicting_exact_actions_refuse_before_mutation(self):
        with tempfile.TemporaryDirectory() as tmp:
            native = Native()
            native.grants.append({**M.scopes(json.loads(RAW))[0], 'actions': ['run', 'read', 'write']})
            with self.assertRaisesRegex(ValueError, 'scope_conflict'):
                M.reconcile(RAW, 'a' * 40, Path(tmp), native)
            self.assertFalse(any('create' in c for c in native.calls))

    def test_uncertain_create_and_reload_are_not_repeated(self):
        for action in ['create', 'reload']:
            with self.subTest(action=action), tempfile.TemporaryDirectory() as tmp:
                native = Native()
                native.fail = action
                with self.assertRaises(TimeoutError):
                    M.reconcile(RAW, 'a' * 40, Path(tmp), native)
                checkpoint = json.loads((Path(tmp) / ('a' * 40 + '.json')).read_text())
                self.assertIn('pending', checkpoint)
                calls = len(native.calls)
                with self.assertRaisesRegex(ValueError, 'checkpoint_requires_inspection'):
                    M.reconcile(RAW, 'a' * 40, Path(tmp), native)
                self.assertEqual(calls, len(native.calls))

    def test_changed_groups_block_acceptance(self):
        with tempfile.TemporaryDirectory() as tmp:
            native = Native()
            def call(args):
                if args == ['access', 'reload']:
                    raise AssertionError('must_not_reload')
                if args == ['access', 'group', 'list'] and any('create' in c for c in native.calls):
                    return []
                return native(args)
            with self.assertRaisesRegex(ValueError, 'prior_policy_changed'):
                M.reconcile(RAW, 'a' * 40, Path(tmp), call)

    def test_both_consumption_paths_require_grants_and_refusal_retains_feed(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / 'orbit-swamp-main-load.py'
            source.write_text((ROOT / 'tools/fixtures/swamp-loader-feed.py').read_text())
            subprocess.run(['patch', '-p1', '-i', str(ROOT / 'platform/swamp-load/manifest-grants.patch')],
                           cwd=root, check=True, capture_output=True)
            ns = {'json': json, 'STATE': root, 'FEED': root, 'APP': root,
                  'atomic': lambda p, data: p.write_text(json.dumps(data)), 'now': lambda: 'fixture'}
            ns = runpy.run_path(str(source), init_globals=ns)
            (root / 'config').mkdir()
            (root / 'config/gateway-workflow-grants.json').write_bytes(RAW)
            manifest = {'target': 'a' * 40}
            (root / 'ready.json').write_text(json.dumps(manifest))
            (root / 'last-proof.json').write_text(json.dumps({'loaded_sha': 'a' * 40,
                'behavior_versions': {'checked': 1, 'matched': 1, 'errors': [], 'mismatches': []}}))
            with patch.dict(sys.modules, manifest_grants=M), patch.object(M, 'reconcile', side_effect=ValueError('refused')), \
                 self.assertRaisesRegex(ValueError, 'refused'):
                ns['consume_accepted_feed'](manifest, 'a' * 40)
            self.assertTrue((root / 'ready.json').exists())
            with patch.dict(sys.modules, manifest_grants=M), patch.object(M, 'reconcile', return_value={'state': 'applied'}) as apply:
                self.assertTrue(ns['consume_accepted_feed'](manifest, 'a' * 40))
                apply.assert_called_once()
            self.assertFalse((root / 'ready.json').exists())

    def test_current_manifest_has_exact_actions_and_conditions(self):
        policy = json.loads((ROOT / 'swamp/config/gateway-workflow-grants.json').read_bytes())
        scopes = M.scopes(policy)
        self.assertEqual(len(policy['workflows']) + len(policy['vaults']), len(scopes))
        self.assertTrue(all(r['condition'].startswith("key.matches('^") for r in scopes if r['resource']['kind'] == 'vault'))
        with self.assertRaisesRegex(ValueError, 'unsupported_manifest'):
            M.scopes({**policy, 'principal': 'user:other'})


if __name__ == '__main__':
    unittest.main()
