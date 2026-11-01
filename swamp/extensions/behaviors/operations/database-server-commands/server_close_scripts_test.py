"""Closure refuses foreign sessions and keeps client secrets out of command arguments."""
import json
import os
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).parent/'scripts'))
import server_close as close
from server_common import Refused


class ClosureScripts(unittest.TestCase):
    def setUp(self):
        self.intent={'host':'127.0.0.1','port':6379,'default_open':True}
        self.material={'password':'private-closure-sentinel'}

    def test_foreign_clients_refuse_before_acl_mutation(self):
        with patch.object(close,'run',return_value=[17,'id=17 name=self\nid=18 name=foreign\n',{'flags':['on','nopass']}]) as run:
            with self.assertRaises(Refused) as error:close.action(self.intent,self.material,'close')
        self.assertEqual(error.exception.code,'database.server_in_use')
        self.assertEqual(run.call_count,1)
        self.assertEqual([row[0]for row in run.call_args.args[2:]],['CLIENT','CLIENT','ACL'])

    def test_closed_intent_only_authenticates_and_pings(self):
        with patch.object(close,'run',return_value=['PONG']) as run:
            self.assertEqual(close.action({**self.intent,'default_open':False},self.material,'probe'),{'ready':True,'was_open':False})
        self.assertEqual(run.call_args.args[2:],(['PING'],))

    def test_probe_reports_original_open_flag_for_both_cli_acl_forms(self):
        for acl,expected in [({'flags':['on','nopass']},True),(['flags',['on'],'passwords',[]],False)]:
            with patch.object(close,'run',return_value=[17,'id=17 name=self\n',acl]):
                self.assertEqual(close.action(self.intent,self.material,'probe'),{'ready':True,'was_open':expected})

    def test_close_uses_password_hash_not_plaintext_or_nopass(self):
        with patch.object(close,'run',side_effect=[[17,'id=17\n',{'flags':['on']}],['OK']]) as run:
            self.assertEqual(close.action(self.intent,self.material,'close'),{'ready':True})
        row=run.call_args.args[2]
        self.assertEqual(row[:4],['ACL','SETUSER','default','resetpass'])
        self.assertTrue(row[4].startswith('#'));self.assertEqual(len(row[4]),65)
        self.assertNotIn(self.material['password'],repr(row))

    def test_environment_file_is_private_and_removed_and_commands_use_stdin(self):
        observed=[]
        def command(argv,stdin):
            self.assertNotIn(self.material['password'],repr(argv))
            path=Path(argv[argv.index('--env-file')+1]);observed.append(path)
            self.assertEqual(os.stat(path).st_mode&0o777,0o600)
            self.assertEqual(path.read_text(),'REDISCLI_AUTH='+self.material['password']+'\n')
            self.assertEqual(stdin,'"PING"\n')
            return SimpleNamespace(returncode=0,stdout='"PONG"\n',stderr='')
        with patch.object(close,'command',side_effect=command):
            self.assertEqual(close.run(self.intent,self.material,['PING']),['PONG'])
        self.assertFalse(observed[0].exists())

    def test_cli_client_list_raw_output_is_parsed_between_json_replies(self):
        reply=SimpleNamespace(returncode=0,stdout='17\nid=17 name=self\n\n{"flags":["on","nopass"]}\n',stderr='')
        with patch.object(close,'command',return_value=reply):
            self.assertEqual(close.action(self.intent,self.material,'probe'),{'ready':True,'was_open':True})

    def test_save_auth_failure_never_falls_back_to_anonymous(self):
        with patch.object(close,'command',return_value=SimpleNamespace(returncode=0,stdout='"PONG"\n',stderr='AUTH failed: private-closure-sentinel')) as run:
            with self.assertRaises(Refused) as error:close.run(self.intent,self.material,['PING'])
        self.assertEqual(run.call_count,1)
        self.assertEqual(error.exception.code,'database.server_command_failed')
        self.assertNotIn('private',str(error.exception))

    def test_invalid_password_never_invokes_client(self):
        with patch.object(close,'command') as run:
            with self.assertRaises(AssertionError):close.action(self.intent,{'password':'bad\nvalue'},'probe')
        run.assert_not_called()

    def test_restore_and_disconnect_use_only_fixed_commands(self):
        for phase,value,row in [('restore','OK',['ACL','SETUSER','default','resetpass','nopass']),('disconnect',0,['CLIENT','KILL','USER','default','SKIPME','yes'])]:
            with patch.object(close,'run',return_value=[value]) as run:
                self.assertEqual(close.action(self.intent,self.material,phase),{'ready':True})
            self.assertEqual(run.call_args.args[2],row)


if __name__=='__main__':unittest.main()
