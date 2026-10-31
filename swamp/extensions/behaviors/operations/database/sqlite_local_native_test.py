"""Execute the shipped static SQLite program through the pinned native bundle."""
import contextlib
import getpass
import json
from pathlib import Path
import shutil
import sqlite3
import sys
import tempfile
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class SqliteLocalNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/_lib')
        folder = self.repo / 'extensions/behaviors/operations/database'
        folder.mkdir(parents=True)
        for name in ('sqlite_actions.ts', 'sqlite_local.ts', 'sqlite_program.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/database' / name, folder)
        (folder / 'proof.ts').write_text('''import {z} from "npm:zod@4.4.3";
import {exportSnapshot, cleanupSnapshot, snapshotFile} from "./sqlite_actions.ts";
export const behavior={type:"@fixture/sqlite-local",version:"2026.10.31.1",files:{snapshot:snapshotFile},
resources:{result:{schema:z.object({bytes:z.number(),sha256:z.string()}),lifetime:"workflow",garbageCollection:1}},
methods:{export:{description:"Exercise the actual bundled local SQLite program",arguments:z.object({run_id:z.string().uuid()}),execute:async(args,ctx)=>{
 const source=JSON.parse(await Deno.readTextFile("source.json"));
 const ref=await exportSnapshot(source,ctx,"snapshot-"+args.run_id);
 await cleanupSnapshot(source);
 return {dataHandles:[await ctx.writeResource("result","result",{bytes:ref.bytes,sha256:ref.sha256})]};
}}}};''')
        source = tempfile.TemporaryDirectory(prefix='sqlite-native-source-')
        self.addCleanup(source.cleanup)
        database = Path(source.name) / 'database.sqlite'
        with contextlib.closing(sqlite3.connect(database, isolation_level=None)) as connection:
            connection.execute('CREATE TABLE proof(value TEXT)')
            connection.execute('INSERT INTO proof VALUES (?)', ('private-native-sqlite-sentinel',))
        database.chmod(0o600)
        export = '/tmp/orbit-sqlite-clone-' + uuid.uuid4().hex
        self.addCleanup(lambda: shutil.rmtree(export, ignore_errors=True))
        self.export = export
        (self.repo / 'source.json').write_text(json.dumps({'variant': 'clone', 'node_id': 12,
            'transport_user': getpass.getuser(), 'checkout': source.name, 'source': str(database), 'directory': export}))
        (self.repo / 'workflows/workflow-sqlite-local-proof.yaml').write_text('''id: 52752e22-2b58-40e6-9cc2-be545179562d
name: sqlite-local-proof
writes: true
vaults: []
jobs:
  - name: source
    steps:
      - name: export
        task:
          type: behavior
          behaviorType: '@fixture/sqlite-local'
          behaviorName: sqlite-local-proof
          methodName: export
          inputs: {run_id: '${{ run.id }}'}
''')

    def test_actual_bundled_domain_code_exports_and_cleans_without_agent(self):
        self.cli('workflow', 'validate', 'sqlite-local-proof')
        result = self.cli('workflow', 'run', 'sqlite-local-proof')
        self.assertFalse(Path(self.export).exists())
        sentinel = b'private-native-sqlite-sentinel'
        self.assertNotIn(sentinel.decode(), result.stdout + result.stderr)
        files = []
        for candidate in (self.repo / '.swamp').rglob('*'):
            if candidate.is_file() and sentinel in candidate.read_bytes():
                self.assertEqual(candidate.name, 'raw', str(candidate))
                self.assertIn('application/vnd.sqlite3', (candidate.parent / 'metadata.yaml').read_text())
                files.append(candidate)
        self.assertEqual(len(files), 1)


if __name__ == '__main__':
    unittest.main()
