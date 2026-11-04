"""Boot behaviour of the nested VMs, as code. These tests never touch a VM."""
from pathlib import Path
import sys
import unittest

SOURCE = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(SOURCE / 'tools/swamp-proof-guest'))
import boot

ADDRESS = '10.45.0.1'
UNITS = '/etc/systemd/system/'


class BootFilesTests(unittest.TestCase):
    def test_every_node_waits_for_the_tunnel_and_the_realtime_url_before_the_agent(self):
        for role in ('gateway', 'node'):
            agent = boot.files(role, ADDRESS)[UNITS + 'orbit-agent.service.d/10-after-wireguard.conf']
            self.assertIn('After=wg-quick@orbit.service', agent)
            self.assertIn('Wants=wg-quick@orbit.service', agent)
            self.assertIn(f'https://{ADDRESS}/api/v1/agent/realtime', agent)
            self.assertIn('ExecStartPre=-/usr/bin/bash', agent, 'a slow Gateway must delay the agent, never fail it')
            self.assertNotIn('@', agent.replace('wg-quick@orbit', ''))

    def test_every_node_disables_cloud_init_and_restores_its_hosts_line(self):
        for role in ('gateway', 'node'):
            files = boot.files(role, ADDRESS)
            self.assertEqual('', files['/etc/cloud/cloud-init.disabled'])
            self.assertIn('PathChanged=/etc/hosts', files[UNITS + 'orbit-hosts.path'])
            self.assertIn(f'{ADDRESS} gateway.orbit swamp.orbit reverb.orbit', files[UNITS + 'orbit-hosts.service'])
            self.assertIn('Type=oneshot', files[UNITS + 'orbit-hosts.service'])

    def test_only_the_gateway_carries_the_runtime_fixes(self):
        node = set(boot.files('node', ADDRESS))
        gateway = boot.files('gateway', ADDRESS)
        self.assertLess(node, set(gateway))
        self.assertEqual({'orbit-swamp-serve', 'orbit-node-worker', 'orbit-proof-agent-view', 'orbit-proof-operations'},
                         {Path(path).parent.name.removesuffix('.service.d') for path in gateway if path not in node and path.startswith(UNITS)})
        with self.assertRaises(ValueError):
            boot.files('router', ADDRESS)

    def test_checkout_consumers_wait_for_the_mount_and_start_without_it(self):
        files = boot.files('gateway', ADDRESS)
        for service in ('orbit-proof-agent-view', 'orbit-proof-operations'):
            unit = files[f'{UNITS}{service}.service.d/10-checkout-mount.conf']
            self.assertIn('RequiresMountsFor=/home/orbit/orbit\n', unit)
            self.assertIn('WorkingDirectory=-/home/orbit/orbit/apps/gateway', unit)
            self.assertIn('mountpoint -q /home/orbit/orbit', unit)
            self.assertIn('[ -f /home/orbit/orbit/apps/gateway/artisan ]', unit)

    def test_swamp_serve_and_worker_start_at_boot_in_order(self):
        files = boot.files('gateway', ADDRESS)
        serve = files[UNITS + 'orbit-swamp-serve.service.d/10-boot.conf']
        worker = files[UNITS + 'orbit-node-worker.service.d/10-boot.conf']
        for unit in (serve, worker):
            self.assertIn('WantedBy=multi-user.target', unit)
        self.assertIn('After=orbit-swamp-serve.service', worker)
        self.assertIn('/dev/tcp/127.0.0.1/9418', worker)

    def test_php_revalidates_files_on_every_request_in_fpm_and_cli(self):
        files = boot.files('gateway', ADDRESS)
        paths = [path for path in files if path.endswith('99-orbit-dev-opcache.ini')]
        self.assertEqual({'fpm', 'cli'}, {Path(path).parent.parent.name for path in paths})
        for path in paths:
            self.assertIn('opcache.validate_timestamps=1\n', files[path])
            self.assertIn('opcache.revalidate_freq=0\n', files[path])


class BootApplyTests(unittest.TestCase):
    def run_apply(self, role):
        calls = []
        boot.apply(lambda argv, input=None: calls.append((argv, input)), role, ADDRESS)
        return calls

    def test_apply_writes_each_file_once_then_reloads_and_enables(self):
        calls = self.run_apply('gateway')
        written = {argv[-1]: contents for argv, contents in calls if argv[0] == 'sh' and contents is not None}
        self.assertEqual(boot.files('gateway', ADDRESS), written)
        order = [argv[:2] for argv, _ in calls if argv[0] == 'systemctl']
        self.assertEqual(['daemon-reload', 'enable', 'start', 'enable', 'try-restart'], [a[1] for a in order])
        enabled = next(argv for argv, _ in calls if argv[:2] == ['systemctl', 'enable'] and 'orbit-swamp-serve' in argv)
        self.assertEqual(['orbit-swamp-serve', 'orbit-node-worker', 'orbit-proof-agent-view', 'orbit-proof-operations'], enabled[2:])

    def test_node_apply_never_touches_gateway_services_or_php(self):
        text = repr(self.run_apply('node'))
        for name in ('orbit-swamp-serve', 'orbit-node-worker', 'php8', 'opcache', 'bootstrap/cache'):
            self.assertNotIn(name, text)

    def test_php_versions_missing_from_the_image_are_skipped_not_created(self):
        php = [argv for argv, _ in self.run_apply('gateway') if argv[-1].startswith('/etc/php/')]
        self.assertEqual(4, len(php))
        for argv in php:
            self.assertIn('[ -d "$(dirname "$1")" ] || exit 0', argv[2])
            self.assertNotIn('mkdir', argv[2])

    def test_laravel_caches_are_removed_and_never_built(self):
        text = repr(self.run_apply('gateway'))
        self.assertIn('rm -f "$1"/bootstrap/cache/config.php', text)
        self.assertIn('/home/orbit/orbit/apps/gateway', text)
        for command in ('config:cache', 'route:cache', 'event:cache', 'artisan optimize'):
            self.assertNotIn(command, text)


if __name__ == '__main__':
    unittest.main()
