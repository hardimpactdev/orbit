"""Legacy sweep policy parity at the shipped phase boundary; no live provider calls."""
import contextlib
import copy
import importlib.util
import io
import json
import sys
import time
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1] / 'extensions/behaviors/operations'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


INCUS = load('incus_sweep', ROOT / 'task-vm-sweep/scripts/incus.py')
with patch.dict(sys.modules, {'upcloud': load('upcloud_sweep_api', ROOT / 'task-vm-lifecycle/scripts/upcloud.py')}):
    CLOUD = load('cloud_sweep', ROOT / 'task-vm-lifecycle/scripts/upcloud_sweep.py')


def intent():
    return {'deadline_at': int(time.time()) + 300, 'ci_runner': False, 'stray_vm_ids': [],
            'scope': {'project': 'tasks', 'pool': 'tasks', 'operator_owned': [], 'live_names': ['tvm-7'],
                      'max_task_vm_id': 10, 'max_build_id': 20, 'recorded_build_ids': [18],
                      'retired_build_ids': [], 'protected_build_ids': [18]},
            'image_cutoff': 1735689600, 'running_stamps': ['20230101000000']}


class SweepPhaseParity(unittest.TestCase):
    def test_sweep_preserves_live_operator_owned_and_post_admission_VMs(self):
        scope = intent()['scope']
        self.assertTrue(INCUS.orphan_instance(scope, 'tvm-6'))
        for name in ('tvm-7', 'tvm-11', 'tvm-6-extra', '../tvm-6', 'operator-vm'):
            self.assertFalse(INCUS.orphan_instance(scope, name))
        for exclusion in ('tasks/tvm-6', 'tasks/*'):
            scope['operator_owned'] = [exclusion]
            self.assertFalse(INCUS.orphan_instance(scope, 'tvm-6'))

    def test_sweep_protects_running_retained_and_post_admission_image_builds(self):
        scope = intent()['scope']
        for suffix in ('', '-smoke', '-attempt-2', '-attempt-2-smoke'):
            self.assertFalse(INCUS.orphan_instance(scope, 'tvm-image-18' + suffix))
            self.assertFalse(INCUS.orphan_instance(scope, 'tvm-image-21' + suffix))
            self.assertTrue(INCUS.orphan_instance(scope, 'tvm-image-16' + suffix))
        scope['recorded_build_ids'].append(16)
        self.assertFalse(INCUS.orphan_instance(scope, 'tvm-image-16'))
        scope['retired_build_ids'].append(16)
        self.assertTrue(INCUS.orphan_instance(scope, 'tvm-image-16'))

    def test_an_expired_admitted_sweep_performs_no_deletion(self):
        value = intent();value['deadline_at'] = 1
        for phase in (INCUS.sweep, CLOUD.sweep):
            calls = []
            with self.assertRaises(ValueError):
                phase(value, lambda *args, calls=calls: calls.append(args))
            self.assertEqual(calls, [])

    def test_cloud_plan_keeps_public_templates_verified_images_and_running_stamps(self):
        def server(identity, name, label, value):
            return {'uuid': identity, 'title': name, 'labels': {'label': [{'key': label, 'value': value}]}}
        identity = '00000000-0000-4000-8000-000000000006'
        servers = [server(identity, 'tvm-6', 'orbit-task-vm', '6'),
                   server(identity, 'tvm-7', 'orbit-task-vm', '7'),
                   server(identity, 'tvm-11', 'orbit-task-vm', '11'),
                   server(identity, 'tvm-image-20230101000000', 'orbit-task-image', '20230101000000'),
                   server(identity, 'tvm-image-20240231000000', 'orbit-task-image', '20240231000000')]
        templates = [{'uuid': identity, 'title': title, 'access': access} for title, access in (
            ('Ubuntu Server 24.04 LTS', 'public'), ('orbit-task-base-20240101000000', 'private'),
            ('orbit-task-base-20240101000000-unverified', 'public'), ('orbit-task-base-20240101000000-unverified', 'private'))]
        plan = CLOUD.plan(intent(), servers, templates)
        self.assertEqual([row['name'] for row in plan], ['tvm-6', 'orbit-task-base-20240101000000-unverified'])

    def test_UpCloud_exact_UUID_name_hostname_label_disagreement_refuses_a_destructive_detail(self):
        item = {'kind': 'server', 'uuid': '00000000-0000-4000-8000-000000000006', 'name': 'tvm-6', 'label': 'orbit-task-vm', 'value': '6'}
        original = {'uuid': item['uuid'], 'title': 'tvm-6', 'hostname': 'tvm-6', 'state': 'started', 'labels': {'label': [{'key': 'orbit-task-vm', 'value': '6'}]}}
        for field in ('uuid', 'title', 'hostname', 'label'):
            current = copy.deepcopy(original)
            if field == 'label':
                current['labels']['label'][0]['value'] = '999'
            else:
                current[field] = 'foreign'
            calls = []
            def api(method, path, *_args, calls=calls, current=current):
                calls.append((method, path))
                return {'server': current}
            with self.subTest(field=field), self.assertRaises(ValueError):
                CLOUD.remove(intent(), item, api)
            self.assertEqual(calls, [('GET', 'server/' + item['uuid'])])

    def test_cloud_duplicate_labels_refuse_before_any_mutation(self):
        row = {'uuid': '00000000-0000-4000-8000-000000000006', 'title': 'tvm-6', 'labels': {'label': [{'key': 'orbit-task-vm', 'value': '6'}] * 2}}
        with self.assertRaises(ValueError):
            CLOUD.plan(intent(), [row], [])

    def test_failed_native_inventory_cannot_become_an_empty_successful_list(self):
        for raw in (None, {}, '', [{'bad': 'tvm-6'}]):
            with self.subTest(raw=raw), self.assertRaises(ValueError):
                INCUS.rows(raw)
        with self.assertRaises(ValueError):
            CLOUD.inventory(lambda *_: {'servers': {'server': None}})


class SweepStrayParity(unittest.TestCase):
    def test_reports_every_project_and_pool_without_deleting_foreign_resources(self):
        value = intent();value['scope']['operator_owned'] = ['default/operator']
        calls = []
        def read(args):
            calls.append(args)
            path = args[-1]
            if path == '/1.0/instances?all-projects=true':
                return ['/1.0/instances/operator?project=default', '/1.0/instances/stray?project=other']
            if path == '/1.0/storage-pools':
                return ['/1.0/storage-pools/tasks', '/1.0/storage-pools/other-pool']
            if 'image' in args:
                return [{'fingerprint': 'a' * 64}]
            return [path.split('?')[0] + '/scratch?project=other']
        output = io.StringIO()
        with contextlib.redirect_stderr(output):
            INCUS.report_strays(value, read)
            INCUS.report_strays(value, read)
        reports = [json.loads(line) for line in output.getvalue().splitlines()]
        self.assertEqual(len(reports), 6)
        self.assertEqual({r['pool'] for r in reports if r['warning'] == 'unowned_volume'}, {'tasks', 'other-pool'})
        self.assertEqual([r['name'] for r in reports if r['warning'] == 'unowned_instance'], ['stray', 'stray'])
        self.assertFalse(any('delete' in args for args in calls))

    def test_ci_role_project_is_owned_without_operator_exceptions(self):
        value = intent()
        def read(args):
            path = args[-1]
            if 'image' in args:
                return []
            if path == '/1.0/storage-pools':
                return ['/1.0/storage-pools/tasks']
            if path == '/1.0/instances?all-projects=true':
                return ['/1.0/instances/ci-1?project=orbit-ci']
            return ['/1.0/storage-pools/tasks/volumes/custom/cache?project=orbit-ci']
        for is_ci, count in ((True, 0), (False, 2)):
            value['ci_runner'] = is_ci
            output = io.StringIO()
            with contextlib.redirect_stderr(output):
                INCUS.report_strays(value, read)
            self.assertEqual(len(output.getvalue().splitlines()), count)
