"""Real disposable SQLite removal, refusal, and missing-file behavior."""
import os
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).parent/'scripts'))
import owned_sqlite_remove as script


class OwnedSqlite(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='owned-sqlite-');self.addCleanup(self.temp.cleanup)
        self.checkout=Path(self.temp.name);self.path=self.checkout/'database.sqlite'
        self.uid=os.geteuid()
        if self.uid==0:
            # Source checks still run; local root test process models the existing non-root account.
            self.getuid=patch.object(script.os,'geteuid',return_value=1000);self.getuid.start();self.addCleanup(self.getuid.stop)
        self.intent={'checkout':str(self.checkout),'path':str(self.path)}

    def test_removes_a_real_database_and_replays_as_absent_without_touching_another_file(self):
        with sqlite3.connect(self.path) as db:db.execute('CREATE TABLE proof(value)')
        sibling=self.checkout/'keep';sibling.write_text('preserve')
        with patch.object(script.os,'geteuid',return_value=self.uid or 1000):
            if self.uid==0:os.chown(self.path,1000,-1)
            self.assertEqual(script.remove(self.intent),{'backend':'absent'})
        self.assertFalse(self.path.exists());self.assertEqual(sibling.read_text(),'preserve')
        self.assertEqual(script.remove(self.intent),{'backend':'absent'})

    def test_missing_parent_is_already_absent_without_creating_it(self):
        self.intent['path']=str(self.checkout/'missing'/'database.sqlite')
        self.assertEqual(script.remove(self.intent),{'backend':'absent'})
        self.assertFalse((self.checkout/'missing').exists())

    def test_refuses_escape_symlinks_directories_and_foreign_owner(self):
        for target in (str(self.checkout)+'-other/database.sqlite',str(self.checkout/'..'/'database.sqlite'),str(self.checkout)):
            with self.assertRaises(script.Refused):script.remove(dict(self.intent,path=target))
        actual=self.checkout/'actual';actual.write_text('keep');self.path.symlink_to(actual)
        with self.assertRaises(script.Refused):script.remove(self.intent)
        self.path.unlink();self.path.mkdir()
        with self.assertRaises(script.Refused):script.remove(self.intent)
        self.path.rmdir();self.path.write_text('foreign')
        with patch.object(script.os,'geteuid',return_value=os.getuid()+12345):
            with self.assertRaises(script.Refused):script.remove(self.intent)
        self.assertEqual(self.path.read_text(),'foreign')
        linked=self.checkout/'linked';linked.symlink_to(self.checkout,target_is_directory=True)
        with self.assertRaises(script.Refused):script.remove(dict(self.intent,path=str(linked/'database.sqlite')))

    def test_root_account_is_refused_and_diagnostics_are_bounded(self):
        with patch.object(script.os,'geteuid',return_value=0):
            with self.assertRaises(script.Refused):script.remove(self.intent)
        value=subprocess.run([sys.executable,'-B',str(Path(script.__file__)),'{"checkout":"private-invalid","path":"/private-secret"}'],capture_output=True,text=True,check=False)
        self.assertEqual(value.returncode,1);self.assertEqual(value.stdout,'{"error_code":"instance.sqlite_copy_refused"}\n');self.assertNotIn('private',value.stderr)


if __name__=='__main__':unittest.main()
