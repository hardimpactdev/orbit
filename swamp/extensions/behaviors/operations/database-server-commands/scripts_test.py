"""Real command construction and private temporary file lifetime, without live hosts."""
import importlib.util
import json
import os
import stat
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS=Path(__file__).parent/'scripts'
sys.path.insert(0,str(SCRIPTS))
import server_common
import user_grant
import user_apply
import user_common
import user_wal


class Scripts(unittest.TestCase):
    def setUp(self):
        self.intent={'driver':'mysql','container':'orbit-process-42-proof','default_open':False,'validate_existing':False,'was_active':False,
                     'volume':'orbit-proof-data','container_input':{'image':'valkey/valkey:8.1'}}
        self.material={'password':'private-password-sentinel','payload':{'users':[]},'acl':'user default on #hash ~* &* +@all\n'}

    def test_client_uses_the_official_password_variable_for_each_driver(self):
        for driver, variable in [('mysql', 'MYSQL_PWD'), ('pgsql', 'PGPASSWORD'), ('redis', 'REDISCLI_AUTH')]:
            self.intent['driver'] = driver
            with server_common.credentials(self.intent, self.material) as name:
                self.assertEqual(Path(name).read_text(), variable + '=' + self.material['password'] + '\n')
            self.assertFalse(Path(name).exists())

    def test_user_sql_stays_on_stdin_and_returns_no_output_for_both_drivers(self):
        material={'password':self.material['password'],'payload':{'password':'private-login-sentinel','salt':'YWFhYWFhYWFhYWFhYWFhYQ=='}}
        for driver in ('mysql','pgsql'):
            intent={'driver':driver,'container':self.intent['container'],'database':'catalog_proof','owner_username':'owner','username':'reader','read_only':True,'replication':False,'known_user':False}
            paths=[]
            def command(argv,stdin=None):
                self.assertNotIn(material['password'],repr(argv));self.assertNotIn(material['payload']['password'],repr(argv))
                paths.append(Path(argv[argv.index('--env-file')+1]));self.assertEqual(stat.S_IMODE(paths[-1].stat().st_mode),0o600)
                self.assertIn('CREATE',stdin)
                return subprocess.CompletedProcess(argv,0,material['password']+material['payload']['password'],'')
            with patch.object(server_common,'command',command):self.assertEqual(user_apply.apply(intent,material),{'ready':True})
            self.assertTrue(all(not path.exists() for path in paths))

    def test_user_replication_wal_refusal_is_read_only(self):
        intent={'driver':'pgsql','container':self.intent['container'],'database':'catalog_proof','owner_username':'owner','username':'reader','read_only':True,'replication':True,'known_user':False}
        material={'password':self.material['password'],'payload':{'password':'private-login-sentinel','salt':'YWFhYWFhYWFhYWFhYWFhYQ=='}}
        with patch.object(user_common,'client',return_value=subprocess.CompletedProcess([],0,'replica\n','')) as client:
            with self.assertRaisesRegex(server_common.Refused,'database.wal_level_not_logical'):user_wal.check(intent,material)
            self.assertEqual(client.call_args.args[-1],'SHOW wal_level;\n')
            self.assertNotIn(material['payload']['password'],client.call_args.args[-1])
        with patch.object(user_common,'client',return_value=subprocess.CompletedProcess([],0,'logical\n','')):
            self.assertEqual(user_wal.check(intent,material),{'ready':True})

    def test_user_invalid_material_or_selected_executable_never_reaches_a_client(self):
        intent={'driver':'mysql','container':self.intent['container'],'database':'catalog_proof','owner_username':'owner','username':'reader','read_only':True,'replication':False,'known_user':False}
        material={'password':'private','payload':{'password':'private-login-sentinel','salt':'YWFhYWFhYWFhYWFhYWFhYQ=='}}
        for raw,secret in [(dict(intent,client=['selected']),material),(dict(intent,container='foreign'),material),(intent,dict(material,password={})),(intent,dict(material,sql='\0private')),(dict(intent,database='app;DROP'),material),(dict(intent,username="app'@'foreign"),material),(intent,dict(material,payload={'password':'','salt':'bad'}))]:
            with patch.object(user_common,'client') as client:
                with self.assertRaisesRegex(server_common.Refused,'database.server_admin_invalid'):user_apply.apply(raw,secret)
                client.assert_not_called()

    def test_unknown_user_action_exception_emits_only_fixed_public_code(self):
        import io
        out=io.StringIO()
        def fail(_intent,_material):raise RuntimeError('private-exception-sentinel')
        with patch.object(sys,'argv',['user_apply.py','{}']),patch.object(sys,'stdin',io.StringIO('{}')),patch.object(sys,'stdout',out):
            with self.assertRaises(SystemExit):server_common.main(fail,error_code='database.user_create_outcome_unknown')
        self.assertEqual(json.loads(out.getvalue()),{'error_code':'database.user_create_outcome_unknown'})


if __name__=='__main__':unittest.main()
