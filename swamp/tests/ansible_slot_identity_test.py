"""Run the slot identity phase twice against a fake incus: the first run writes the tree, the second changes nothing."""
import importlib.util
import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLAYBOOK = ROOT / 'ansible/operation-task-vm-slot-enroll.yml'
VOLUME = 'fixture-tvm-1-identity'
INCUS = r'''#!/usr/bin/env bash
# Just enough of `incus storage volume` for the identity phase; a volume is a folder under $FAKE_INCUS_STORE.
set -eu
args=("$@")
[ "${args[0]}" = --project ] && args=("${args[@]:2}")
[ "${args[0]}" = storage ] && [ "${args[1]}" = volume ] || { echo "unsupported: $*" >&2; exit 2; }
case "${args[2]}" in
  show) [ -d "$FAKE_INCUS_STORE/${args[4]}" ] ;;
  create) mkdir -p "$FAKE_INCUS_STORE/${args[4]}" ;;
  file)
    rest=("${args[@]:4}")
    if [ "${args[3]}" = pull ]; then
      [ "${rest[0]}" = --create-dirs ] && rest=("${rest[@]:1}")
      ref=${rest[1]}; source="$FAKE_INCUS_STORE/${ref%%/*}/${ref#*/}"
      [ -f "$source" ] || { echo "Error: file does not exist" >&2; exit 1; }
      if [ "${rest[2]}" = - ]; then cat "$source"; else mkdir -p "$(dirname "${rest[2]}")"; cp "$source" "${rest[2]}"; fi
    else
      # push --create-dirs --mode M --uid 0 --gid 0 SOURCE POOL VOLUME/PATH
      ref=${rest[9]}
      install -D -m "${rest[2]}" "${rest[7]}" "$FAKE_INCUS_STORE/${ref%%/*}/${ref#*/}"
    fi ;;
esac
'''
WG = r'''#!/usr/bin/env bash
set -eu
case "$1" in
  genkey) openssl rand -base64 32 ;;
  pubkey) openssl dgst -sha256 -binary | openssl base64 ;;
esac
'''


@unittest.skipUnless(shutil.which('ansible-playbook') and shutil.which('ssh-keygen') and shutil.which('openssl'), 'needs ansible, ssh-keygen and openssl')
class SlotIdentityPhaseTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        base = Path(self.directory.name)
        self.store, self.run_folder = base / 'store', base / 'run'
        for folder in (self.store, self.run_folder, base / 'bin', base / 'home'):
            folder.mkdir()
        for name, text in (('incus', INCUS), ('wg', WG)):
            (base / 'bin' / name).write_text(text)
            (base / 'bin' / name).chmod(0o755)
        (base / 'inventory.yml').write_text(json.dumps({'all': {'children': {'slot_host': {'hosts': {'host': {'ansible_connection': 'local'}}}}}}))
        files = {
            'etc/wireguard/orbit.conf': '[Interface]\nPostUp = wg set %i private-key /etc/wireguard/orbit.key\n',
            'etc/wireguard/orbit.dns-link': 'orbit\n10.44.0.1\n',
            'etc/cloud/cloud.cfg.d/99-orbit-slot.cfg': 'ssh_deletekeys: false\n',
            'home/orbit/.pi/agent/models.json': '{"apiKey": "@MODEL_KEY@", "note": "{{ literal }}"}\n',
        }
        identity = {'project': 'orbit-tasks', 'pool': 'orbit-tasks', 'volume': VOLUME, 'files': files, 'revision': 'b' * 64, 'model_key_mark': '@MODEL_KEY@'}
        # The builder writes the vars file, so this also covers the quoting of text that holds a template mark.
        spec = {'identity': identity, 'run_folder': str(self.run_folder), 'secrets': {'pi_token': 'p' * 64, 'model_key': 'm' * 64}}
        module = importlib.util.spec_from_file_location('build_slot_vars', ROOT / 'ansible/build_slot_vars.py')
        builder = importlib.util.module_from_spec(module)
        module.loader.exec_module(builder)
        (base / 'vars.yml').write_text(builder.unsafe(spec) + '\n')
        self.base = base

    def run_phase(self):
        env = {**os.environ, 'PATH': f'{self.base / "bin"}:{os.environ["PATH"]}', 'FAKE_INCUS_STORE': str(self.store), 'HOME': str(self.base / 'home'),
               'ANSIBLE_HOME': str(self.base / 'home/.ansible'), 'ANSIBLE_HOST_KEY_CHECKING': 'false', 'ANSIBLE_FORCE_COLOR': '0', 'ANSIBLE_LOCALHOST_WARNING': 'false'}
        result = subprocess.run(['ansible-playbook', '-i', str(self.base / 'inventory.yml'), '-e', f'@{self.base / "vars.yml"}', '-e', 'ansible_become=false',
                                 '--tags', 'identity', str(PLAYBOOK)], capture_output=True, text=True, env=env, check=False, cwd=self.base)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        changed = int(re.search(r'changed=(\d+)', result.stdout).group(1))
        self.assertNotIn('p' * 64, result.stdout + result.stderr)
        self.assertNotIn('m' * 64, result.stdout + result.stderr)
        return changed

    def tree(self):
        volume = self.store / VOLUME
        return {str(path.relative_to(volume)): (path.read_bytes(), path.stat().st_mode & 0o777) for path in sorted(volume.rglob('*')) if path.is_file()}

    def test_second_run_changes_nothing_and_keeps_every_key(self):
        self.assertGreater(self.run_phase(), 0)
        first = self.tree()
        self.assertEqual(len(first), 14)
        self.assertEqual(first['REVISION'], (b'b' * 64, 0o644))
        self.assertEqual(first['etc/orbit/agent/secret'][1], 0o600)
        self.assertEqual(first['etc/ssh/ssh_host_ed25519_key'][1], 0o600)
        self.assertEqual(first['home/orbit/.pi/agent/orbit-token'], (b'p' * 64, 0o600))
        models = first['home/orbit/.pi/agent/models.json'][0].decode()
        self.assertIn('"apiKey": "' + 'm' * 64 + '"', models)
        self.assertIn('{{ literal }}', models)
        public = json.loads((self.run_folder / 'public.json').read_text())
        self.assertEqual(public['revision'], 'b' * 64)
        self.assertRegex(public['ssh_host_key'], r'^ssh-ed25519 \S+$')
        self.assertRegex(public['wireguard_public_key'], r'^[A-Za-z0-9+/]{43}=$')

        self.assertEqual(self.run_phase(), 0)
        self.assertEqual(self.tree(), first)

    def test_a_missing_public_host_key_is_rebuilt_from_the_private_key(self):
        self.run_phase()
        first = self.tree()
        (self.store / VOLUME / 'etc/ssh/ssh_host_rsa_key.pub').unlink()

        self.assertGreater(self.run_phase(), 0)
        again = self.tree()
        self.assertEqual(again['etc/ssh/ssh_host_rsa_key'], first['etc/ssh/ssh_host_rsa_key'])
        self.assertTrue(again['etc/ssh/ssh_host_rsa_key.pub'][0].startswith(b'ssh-rsa '))
        self.assertEqual(self.run_phase(), 0)


if __name__ == '__main__':
    unittest.main()
