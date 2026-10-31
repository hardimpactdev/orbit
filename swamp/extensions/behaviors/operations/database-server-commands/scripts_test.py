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
import inspect_policy
import ready
import wait_ready
import write_acl


class Scripts(unittest.TestCase):
    def setUp(self):
        self.intent={'driver':'mysql','container':'orbit-process-42-proof','default_open':False,'validate_existing':False,'was_active':False,
                     'volume':'orbit-proof-data','container_input':{'image':'valkey/valkey:8.1'}}
        self.material={'password':'private-password-sentinel','payload':{'users':[]},'acl':'user default on #hash ~* &* +@all\n'}

    def test_client_password_is_only_in_removed_private_file(self):
        paths=[]
        def command(argv,stdin=None):
            self.assertNotIn(self.material['password'],repr(argv))
            path=Path(argv[argv.index('--env-file')+1]);paths.append(path)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600)
            self.assertEqual(path.read_text(),'MYSQL_PWD='+self.material['password']+'\n')
            self.assertEqual(stdin,'SELECT 1;\n')
            return subprocess.CompletedProcess(argv,0,'1\n','')
        with patch.object(server_common,'command',command):
            self.assertEqual(ready.ready(self.intent,self.material),{'ready':True})
        self.assertTrue(all(not path.exists() for path in paths))

    def test_client_uses_the_official_password_variable_for_each_driver(self):
        for driver, variable in [('mysql', 'MYSQL_PWD'), ('pgsql', 'PGPASSWORD'), ('redis', 'REDISCLI_AUTH')]:
            self.intent['driver'] = driver
            with server_common.credentials(self.intent, self.material) as name:
                self.assertEqual(Path(name).read_text(), variable + '=' + self.material['password'] + '\n')
            self.assertFalse(Path(name).exists())

    def test_client_failure_keeps_diagnostics_local_and_removes_file(self):
        paths=[]
        def command(argv,stdin=None):
            paths.append(Path(argv[argv.index('--env-file')+1]))
            return subprocess.CompletedProcess(argv,1,'',self.material['password'])
        with patch.object(server_common,'command',command),self.assertRaisesRegex(server_common.Refused,'database.server_not_ready'):
            ready.ready(self.intent,self.material)
        self.assertTrue(all(not path.exists() for path in paths))

    def test_adoption_refuses_wrong_login_before_effects(self):
        self.intent.update(driver='redis',validate_existing=True)
        with patch.object(inspect_policy,'valkey',side_effect=server_common.Refused('database.server_adoption_refused')):
            with self.assertRaisesRegex(server_common.Refused,'database.server_adoption_refused'):
                inspect_policy.inspect(self.intent,self.material)

    def test_open_repair_never_reopens_closed_policy(self):
        self.intent.update(driver='redis',was_active=True,default_open=True)
        with patch.object(inspect_policy,'valkey',return_value='user default on #abcd ~* &* +@all\n'):
            with self.assertRaisesRegex(server_common.Refused,'database.server_policy_changed'):
                inspect_policy.inspect(self.intent,self.material)

    def test_acl_publication_sends_bytes_only_on_stdin(self):
        def command(argv,stdin=None):
            self.assertNotIn(self.material['acl'],repr(argv))
            self.assertEqual(stdin,self.material['acl'])
            self.assertIn('type=volume,source=orbit-proof-data,target=/data',argv)
            return subprocess.CompletedProcess(argv,0,'','')
        with patch.object(write_acl,'command',command):
            self.assertEqual(write_acl.write(self.intent,self.material),{'ready':True})

    def test_healthy_and_existing_uninstrumented_servers_need_no_event_wait(self):
        for health in ('healthy','none'):
            with patch.object(wait_ready,'command',return_value=subprocess.CompletedProcess([],0,health+'\n','')):
                with patch.object(wait_ready.subprocess,'Popen') as start:
                    self.assertEqual(wait_ready.wait(self.intent,self.material),{'ready':True})
                    start.assert_not_called()

    def test_initial_health_inspection_has_the_readiness_deadline(self):
        with patch.object(wait_ready,'command',side_effect=subprocess.TimeoutExpired('inspect',120)) as inspect:
            with patch.object(wait_ready.subprocess,'Popen') as start:
                with self.assertRaisesRegex(server_common.Refused,'database.server_not_ready'):
                    wait_ready.wait(self.intent,self.material)
                self.assertEqual(inspect.call_args.kwargs,{'timeout':120})
                start.assert_not_called()

    def test_event_wait_uses_only_the_remaining_probe_budget(self):
        event=MagicMock()
        observed=subprocess.CompletedProcess([],0,'starting\n','')
        with patch.object(wait_ready,'command',return_value=observed):
            with patch.object(wait_ready.time,'monotonic',side_effect=[100,105]):
                with patch.object(wait_ready.subprocess,'Popen',return_value=event):
                    with patch.object(wait_ready.subprocess,'run',return_value=subprocess.CompletedProcess([],0,'health_status: healthy\n','')) as read:
                        self.assertEqual(wait_ready.wait(self.intent,self.material),{'ready':True})
                        self.assertEqual(read.call_args.kwargs['timeout'],115)
        event.stdout.close.assert_called_once()
        event.terminate.assert_called_once()
        event.wait.assert_called_once_with(timeout=1)

    def test_event_timeout_refuses_and_escalates_cleanup_with_fixed_bounds(self):
        event=MagicMock()
        event.wait.side_effect=[subprocess.TimeoutExpired('events',1),None]
        with patch.object(wait_ready,'command',return_value=subprocess.CompletedProcess([],0,'starting\n','')):
            with patch.object(wait_ready.subprocess,'Popen',return_value=event):
                with patch.object(wait_ready.subprocess,'run',side_effect=subprocess.TimeoutExpired('head',120)):
                    with self.assertRaisesRegex(server_common.Refused,'database.server_not_ready'):
                        wait_ready.wait(self.intent,self.material)
        event.terminate.assert_called_once()
        event.kill.assert_called_once()
        self.assertEqual([call.kwargs for call in event.wait.call_args_list],[{'timeout':1},{'timeout':1}])


if __name__=='__main__':unittest.main()
