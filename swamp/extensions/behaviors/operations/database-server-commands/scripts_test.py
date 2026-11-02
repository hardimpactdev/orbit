"""Real command construction and private temporary file lifetime, without live hosts."""
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS=Path(__file__).parent/'scripts'
sys.path.insert(0,str(SCRIPTS))
import server_common


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

    def test_unknown_action_exception_emits_only_fixed_public_code(self):
        import io
        out=io.StringIO()
        def fail(_intent,_material):raise RuntimeError('private-exception-sentinel')
        with patch.object(sys,'argv',['action.py','{}']),patch.object(sys,'stdin',io.StringIO('{}')),patch.object(sys,'stdout',out),self.assertRaises(SystemExit):
            server_common.main(fail,error_code='database.create_outcome_unknown')
        self.assertEqual(json.loads(out.getvalue()),{'error_code':'database.create_outcome_unknown'})


if __name__=='__main__':unittest.main()
