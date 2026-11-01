"""Real SQLite clone files and bounded MySQL action failures."""
import getpass
import json
import os
import shutil
import sqlite3
import sys
import tempfile
import unittest
import uuid
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).parent/'scripts'))
import sqlite_clone_common as common
import sqlite_clone_local as local
import sqlite_clone_export as export
import sqlite_clone_prepare as prepare
import sqlite_clone_install as install
import sqlite_clone_cleanup_source as cleanup_source
import sqlite_clone_cleanup_target as cleanup_target
import mysql_clone_copy as mysql_copy
from server_common import Refused

class CloneScripts(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory(prefix='database-clone-');self.addCleanup(self.temp.cleanup)
  root=Path(self.temp.name);self.source=root/'source';self.target=root/'target';self.source.mkdir();self.target.mkdir()
  self.database=self.source/'database.sqlite'
  with sqlite3.connect(self.database)as db:db.execute('CREATE TABLE proof(v)');db.execute('INSERT INTO proof VALUES (7)')
  self.database.chmod(0o640)
  self.directory='/tmp/orbit-sqlite-clone-'+uuid.uuid4().hex
  self.i={'source':{'checkout':str(self.source),'source':str(self.database),'directory':self.directory},'target':{'checkout':str(self.target),'target':str(self.target/'database.sqlite'),'incoming':str(self.target/('.orbit-sqlite-'+uuid.uuid4().hex))}}
  self.addCleanup(lambda:shutil.rmtree(self.directory,ignore_errors=True))
 def rows(self):
  with sqlite3.connect(self.target/'database.sqlite')as db:return db.execute('SELECT v FROM proof').fetchall()
 def test_local_clone_keeps_committed_wal_rows_and_source(self):
  with sqlite3.connect(self.database)as db:
   db.execute('PRAGMA journal_mode=WAL');db.execute('INSERT INTO proof VALUES (8)');db.commit()
   self.assertEqual(local.apply(self.i),{'backend':'active'});self.assertEqual(self.rows(),[(7,),(8,)])
   self.assertEqual(db.execute('SELECT count(*) FROM proof').fetchone(),(2,))
  self.assertEqual((self.target/'database.sqlite').stat().st_mode&0o777,0o640)
 def test_remote_snapshot_verifies_digest_and_installs_without_old_sidecars(self):
  ref=export.apply(self.i);self.assertEqual(ref['copy'],'snapshot');self.assertNotIn('proof',json.dumps(ref))
  prepare.apply(self.i);shutil.copyfile(Path(self.directory)/'snapshot.sqlite',self.i['target']['incoming'])
  for suffix in ['-wal','-shm']:(self.target/('database.sqlite'+suffix)).write_bytes(b'old')
  with patch.object(sys,'argv',['fixture','intent',json.dumps(ref)]):self.assertEqual(install.apply(self.i),{'backend':'active'})
  self.assertEqual(self.rows(),[(7,)]);self.assertFalse((self.target/'database.sqlite-wal').exists())
  self.assertEqual(cleanup_source.apply(self.i),{'ready':True});self.assertEqual(cleanup_target.apply(self.i),{'ready':True})
  self.assertFalse(Path(self.directory).exists());self.assertFalse(Path(self.i['target']['incoming']).exists())
 def test_bad_snapshot_cannot_replace_existing_database(self):
  ref=export.apply(self.i);prepare.apply(self.i);shutil.copyfile(Path(self.directory)/'snapshot.sqlite',self.i['target']['incoming'])
  previous=self.target/'database.sqlite';previous.write_bytes(b'previous');ref['sha256']='0'*64
  with patch.object(sys,'argv',['fixture','intent',json.dumps(ref)]):
   with self.assertRaises(common.Refused):install.apply(self.i)
  self.assertEqual(previous.read_bytes(),b'previous');cleanup_target.apply(self.i);cleanup_source.apply(self.i)
 def test_cancel_cleanup_before_parent_exists_is_absent_without_creation(self):
  missing=self.target/'missing';self.i['target'].update(target=str(missing/'database.sqlite'),incoming=str(missing/('.orbit-sqlite-'+uuid.uuid4().hex)))
  self.assertEqual(cleanup_target.apply(self.i),{'ready':True});self.assertFalse(missing.exists())
 def test_path_escape_and_symlink_are_refused(self):
  self.i['source']['source']=str(self.target/'database.sqlite')
  with self.assertRaises(common.Refused):local.apply(self.i)
  selected=self.source/'linked.sqlite';selected.symlink_to(self.database);self.i['source']['source']=str(selected)
  with self.assertRaises(common.Refused):local.apply(self.i)
 def test_mysql_copy_rechecks_source_owner_then_resets_before_pipeline_failure(self):
  from types import SimpleNamespace
  calls=[];i={'container':'orbit-process-42-proof','driver':'mysql','databases':['target','target_test']}
  with patch.object(mysql_copy,'source_exists',side_effect=lambda*a:calls.append('source')),patch.object(mysql_copy,'probe_user',side_effect=lambda*a:calls.append('user')),patch.object(mysql_copy,'probe_databases',side_effect=lambda*a:calls.append('ownership')),patch.object(mysql_copy,'authenticate',side_effect=lambda*a:calls.append('authenticate')),patch.object(mysql_copy,'sql',side_effect=lambda a,b,s:calls.append(s)),patch.object(mysql_copy,'command',return_value=SimpleNamespace(returncode=1)):
   with self.assertRaises(Refused):mysql_copy.apply(i,{'password':'private-not-argv'},'source')
  self.assertEqual(calls[:4],['source','user','ownership','authenticate']);self.assertIn('DROP DATABASE IF EXISTS `target`',calls[4]);self.assertIn('CREATE DATABASE `target_test`',calls[4])

if __name__=='__main__':unittest.main()
