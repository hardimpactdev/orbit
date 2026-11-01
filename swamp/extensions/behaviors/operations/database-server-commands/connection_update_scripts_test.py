"""Read-only index policy and private official-client delivery."""
import json
import stat
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).parent/'scripts'))
import connection_update
import create_common
import create_valkey
from server_common import Refused


class ConnectionUpdate(unittest.TestCase):
    def setUp(self):
        self.intent={'validate_index':True,'host':'10.44.0.9','port':6379,'index':'3'}
        self.material={'password':'private-update-client-sentinel'}

    def test_config_read_uses_official_cli_and_removed_private_env_file(self):
        paths=[]
        def command(argv,stdin):
            self.assertNotIn(self.material['password'],repr(argv))
            path=Path(argv[argv.index('--env-file')+1]);paths.append(path)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600)
            self.assertEqual(path.read_text(),'REDISCLI_AUTH='+self.material['password']+'\n')
            self.assertEqual(argv[-3:],['CONFIG','GET','databases']);self.assertIsNone(stdin)
            self.assertEqual(argv[argv.index('-h')+1],self.intent['host'])
            self.assertEqual(argv[argv.index('-p')+1],str(self.intent['port']))
            return subprocess.CompletedProcess(argv,0,json.dumps({'databases':'16'}),'')
        with patch.object(create_common,'command',command):
            self.assertEqual(connection_update.check(self.intent,self.material),{'backend':'active'})
        self.assertTrue(paths and all(not p.exists() for p in paths))

    def test_out_of_range_or_huge_index_refuses_without_a_write(self):
        for selected in ('16','9'*255):
            with patch.object(create_valkey,'valkey',return_value=['databases','16']) as cli:
                with self.assertRaisesRegex(Refused,'database.index_invalid'):
                    connection_update.check(dict(self.intent,index=selected),self.material)
                cli.assert_called_once_with(dict(self.intent,index=int(selected),driver='redis'),self.material,'CONFIG','GET','databases')

    def test_malformed_limits_and_private_transport_failures_are_bounded(self):
        for response in ({'databases':'0'},['other','16'],{'databases':'private'},None):
            with patch.object(create_valkey,'valkey',return_value=response):
                with self.assertRaisesRegex(Refused,'database.server_command_failed'):
                    connection_update.check(self.intent,self.material)
        for response in ('private-response','"WRONGPASS private-response"'):
            with patch.object(create_common,'command',return_value=subprocess.CompletedProcess([],1,response,self.material['password'])):
                with self.assertRaisesRegex(Refused,'database.server_command_failed'):
                    connection_update.check(self.intent,self.material)

    def test_invalid_intent_never_opens_a_client(self):
        for change in ({'host':'foreign; command'},{'port':0},{'index':'-1'},{'validate_index':False},{'client':['foreign']}):
            with patch.object(create_valkey,'valkey') as cli:
                with self.assertRaisesRegex(Refused,'database.server_command_failed'):
                    connection_update.check(dict(self.intent,**change),self.material)
                cli.assert_not_called()


if __name__=='__main__':unittest.main()
