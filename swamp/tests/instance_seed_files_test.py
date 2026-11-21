"""The default's clone files and its seed's manifest (swamp/ansible/instance_seed_files.py): each item lands as the executor's
instance.prepare places it, and the manifest records what a clone's prepare compares, so a seed whose files a clone renders the same
way is skipped item by item."""
import hashlib
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('instance_seed_files', ROOT / 'swamp/ansible/instance_seed_files.py')
seed = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(seed)

UID, GID = os.getuid(), os.getgid()
# The test runs as its own user, so the root-owned folder is the user's here.
seed.FOLDER_OWNER = (UID, GID)


def digest(text):
    return hashlib.sha256(text.encode()).hexdigest()


class SeedFilesTest(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp()

    def path(self, name):
        return os.path.join(self.root, name)

    def test_each_item_lands_as_prepare_places_it_and_the_manifest_records_its_state(self):
        items = [
            {'file': {'path': self.path('etc/gitconfig'), 'uid': UID, 'gid': GID, 'mode': '0644', 'content': '[safe]\n', 'parents': True}},
            {'folder': {'path': self.path('agent'), 'files': [{'path': 'AGENTS.md', 'content': '# Note\n'},
                                                              {'path': '.skills/one/SKILL.md', 'content': 'skill\n'}]}},
            {'link': {'path': self.path('home/.claude/CLAUDE.md'), 'target': self.path('agent/AGENTS.md'), 'owner': {'uid': UID, 'gid': GID}}},
        ]
        os.makedirs(self.path('agent'))
        Path(self.path('agent/removed.md')).write_text('gone\n')

        for item in items:
            seed.place(item, '')
        manifest = seed.record(items, '')

        self.assertEqual(Path(self.path('etc/gitconfig')).read_text(), '[safe]\n')
        self.assertEqual(manifest['files'][self.path('etc/gitconfig')], {'sha256': digest('[safe]\n'), 'uid': UID, 'gid': GID, 'mode': '0644'})
        # The folder is made again with exactly its files: a file the Project removed is gone.
        self.assertEqual(manifest['folders'][self.path('agent')],
                         {'files': {'AGENTS.md': digest('# Note\n'), '.skills/one/SKILL.md': digest('skill\n')}, 'skills': True})
        self.assertEqual(manifest['links'], {self.path('home/.claude/CLAUDE.md'): self.path('agent/AGENTS.md')})

    def test_an_nss_entry_is_appended_once_and_the_manifest_keeps_the_stores_lines(self):
        home = self.path('home')
        os.makedirs(home)
        store = self.path('home/.pki/nssdb/pkcs11.txt')
        item = {'nss': {'home': home, 'directories': [self.path('home/.pki'), self.path('home/.pki/nssdb')], 'path': store,
                        'entry': 'library=/opt/p11-kit-trust.so\nname=Orbit\n', 'uid': UID, 'gid': GID, 'mode': '0600',
                        'directory_mode': '0700', 'library': 'p11-kit-modules', 'version': '1.0'}}
        seed.installed = lambda package: '1.0'

        seed.place(item, '')
        seed.place(item, '')

        self.assertEqual(Path(store).read_text(), 'library=/opt/p11-kit-trust.so\nname=Orbit\n')
        self.assertEqual(seed.record([item], '')['nss'][store], {'lines': ['library=/opt/p11-kit-trust.so', 'name=Orbit'], 'version': '1.0'})

    def test_the_bundle_records_each_certificate_by_the_digest_of_its_body(self):
        pem = '-----BEGIN CERTIFICATE-----\nAAAA\nBBBB\n-----END CERTIFICATE-----\n'

        self.assertEqual(seed.certificates(pem + pem), [digest('AAAABBBB')])


if __name__ == '__main__':
    unittest.main()
