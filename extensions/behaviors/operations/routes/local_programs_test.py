"""Run fixed native programs against disposable files and fake service/UFW boundaries."""
import os
from pathlib import Path
import tempfile
import subprocess
import sys
import select
import types
import unittest
ROOT=Path(__file__).resolve().parent

def program(name):
    source=(ROOT/name).read_text().split('.raw`',1)[1].rsplit('`;',1)[0]
    module=types.ModuleType(name);exec(compile(source,name,'exec'),module.__dict__)
    return module

class SharedPhp(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name);self.php=program('php-shared-program.ts')
        self.php.ROOT=self.root;self.php.UID=os.getuid();self.php.GID=os.getgid();self.calls=[]
        def call(argv):
            self.calls.append(argv)
            return 0
        self.php.call=call
    def version(self,version,text):
        directory=self.root/f'etc/php/{version}/fpm/pool.d';directory.mkdir(parents=True)
        (directory.parent/'php-fpm.conf').write_text(f'[global]\ninclude = /etc/php/{version}/fpm/pool.d/*.conf\n')
        target=directory/'orbit-scopes.conf';target.write_text(text);return target
    def pool(self,name,directory='/srv/app'):
        (self.root/directory.lstrip('/')).mkdir(parents=True,exist_ok=True)
        return f'[orbit-app-instance-{name}]\nchdir = {directory}\nlisten = /run/orbit-{name}.sock\n'
    def test_empty_set_retires_last_pool_and_converges_service(self):
        target=self.version('8.5',self.pool(4))
        result=self.php.main({'configurations':{}})
        self.assertEqual(result,{'changed':True,'missing_pools':[]});self.assertFalse(target.exists())
        self.assertIn(['/usr/bin/systemctl','reload-or-restart','php8.5-fpm'],self.calls)
    def test_failed_validation_changes_no_live_file_or_service(self):
        old=self.pool(4);target=self.version('8.5',old)
        self.php.call=lambda argv: 1 if argv[0].startswith('/usr/sbin/php-fpm') else self.fail('Service changed before validation')
        result=self.php.main({'configurations':{'8.5':self.pool(5)}})
        self.assertEqual(result,{'error_code':'php.shared_pool_failed'});self.assertEqual(target.read_text(),old)
    def test_cross_version_move_retires_socket_before_new_version_activation(self):
        pool=self.pool(4);old=self.version('8.4',pool);new=self.version('8.5','')
        result=self.php.main({'configurations':{'8.5':pool}})
        self.assertNotIn('error_code',result);self.assertFalse(old.exists());self.assertEqual(new.read_text(),pool)
        reloads=[args[-1] for args in self.calls if args[:2]==['/usr/bin/systemctl','reload-or-restart']]
        self.assertEqual(reloads,['php8.4-fpm','php8.5-fpm'])
    def test_failed_activation_restores_previous_file_and_service(self):
        old=self.pool(4);target=self.version('8.5',old);calls=[]
        def call(argv):
            calls.append(argv)
            return 1 if argv[:2]==['/usr/bin/systemctl','reload-or-restart'] and sum(a[:2]==argv[:2] for a in calls)==1 else 0
        self.php.call=call
        result=self.php.main({'configurations':{'8.5':self.pool(5)}})
        self.assertEqual(result,{'error_code':'php.shared_pool_failed'});self.assertEqual(target.read_text(),old)
    def test_missing_source_is_reported_and_never_published(self):
        self.version('8.5','');result=self.php.main({'configurations':{'8.5':'[orbit-app-instance-4]\nchdir = /absent\n'}})
        self.assertEqual(result['missing_pools'],[dict(pool='orbit-app-instance-4',version='8.5',directory='/absent',installed=False)])
        self.assertFalse((self.root/'etc/php/8.5/fpm/pool.d/orbit-scopes.conf').exists())
    def test_symlink_pool_file_refuses_before_any_mutation(self):
        target=self.version('8.5',self.pool(4));target.unlink();target.symlink_to(self.root/'outside')
        self.assertEqual(self.php.main({'configurations':{}}),{'error_code':'php.shared_pool_unsafe'});self.assertEqual(self.calls,[])

class Firewall(unittest.TestCase):
    def setUp(self):
        self.firewall=program('firewall-program.ts')
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.firewall.LOCK=Path(self.temporary.name)/'firewall.lock';self.firewall.LOCK_UID=os.getuid()
        self.shape=dict(comment='orbit:route-7-lan',action='allow',direction='in',source='192.168.6.1',destination='192.168.6.18',port='443',protocol='tcp',in_interface=None,out_interface=None,family='v4')
    def test_live_inventory_retains_malformed_owned_rules(self):
        backend,rows=self.firewall.live('Status: active\n[ 1] 192.168.6.18 443/tcp ALLOW IN 192.168.6.1 # orbit:route-7-lan\n[2] invalid # orbit:route-7-lan\n')
        self.assertEqual(backend,'active');self.assertEqual(self.firewall.ownership(rows,self.shape),'drift')
    def test_route_removal_deletes_only_exact_comment_descending_and_verifies(self):
        rows=[dict(number=1,comment='orbit:route-70-lan',shape=None),dict(number=2,comment='orbit:route-7-lan',shape=None),dict(number=3,comment='orbit:route-7-lan',shape=None)];calls=[]
        self.firewall.status=lambda:('active',list(rows))
        def change(args):
            calls.append(args);rows[:]=[r for r in rows if r['number']!=int(args[-1])]
        self.firewall.change=change
        self.assertEqual(self.firewall.main(dict(mode='route',operation='remove',route_id=7)),dict(matches=True))
        self.assertEqual(calls,[['--force','delete','3'],['--force','delete','2']]);self.assertEqual(len(rows),1)
    def test_role_rule_drift_refuses_before_deleting_or_adding_anything(self):
        wrong=dict(self.shape,source='192.168.6.99');self.firewall.status=lambda:('active',[dict(number=1,comment=wrong['comment'],shape=wrong)])
        self.firewall.change=lambda args:self.fail('Drift caused mutation')
        result=self.firewall.main(dict(mode='rules',desired=[dict(shape=self.shape,replace_port=False)],remove=[],enable=False))
        self.assertEqual(result,dict(error_code='node.firewall_convergence_failed'))
    def test_role_removal_preserves_foreign_same_comment_shape(self):
        wrong=dict(self.shape,port='444');self.firewall.status=lambda:('active',[dict(number=1,comment=wrong['comment'],shape=wrong)])
        self.firewall.change=lambda args:self.fail('Foreign shape was removed')
        self.assertEqual(self.firewall.main(dict(mode='rules',desired=[],remove=[self.shape],enable=False)),dict(error_code='node.firewall_convergence_failed'))
    def test_route_and_rules_observe_and_mutate_inside_same_locked_transaction(self):
        def assert_locked(_payload):
            import fcntl
            fd=os.open(self.firewall.LOCK,os.O_RDWR)
            try:
                with self.assertRaises(BlockingIOError): fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            finally: os.close(fd)
            return {'matches':True}
        self.firewall.route=assert_locked;self.firewall.rules=assert_locked
        self.assertEqual(self.firewall.main({'mode':'route'}),{'matches':True})
        self.assertEqual(self.firewall.main({'mode':'rules'}),{'matches':True})
    def test_lock_refuses_symlink_or_broad_permissions(self):
        target=Path(self.temporary.name)/'other';target.write_text('safe')
        self.firewall.LOCK.symlink_to(target)
        self.assertEqual(self.firewall.main({'mode':'route'}),{'error_code':'firewall.lock_failed'})
        self.firewall.LOCK.unlink();self.firewall.LOCK.write_text('');self.firewall.LOCK.chmod(0o644)
        self.assertEqual(self.firewall.main({'mode':'route'}),{'error_code':'firewall.lock_failed'})
    def test_two_processes_serialize_observation_and_confirmation(self):
        source=(ROOT/'firewall-program.ts').read_text().split('.raw`',1)[1].rsplit('`;',1)[0]
        script="import os,pathlib,sys; scope={'__name__':'fixture'}; exec("+repr(source)+",scope); scope['LOCK']=pathlib.Path(sys.argv[1]); scope['LOCK_UID']=os.getuid(); lock=scope['transaction_lock'](); lock.__enter__(); print('entered',flush=True); sys.stdin.readline(); lock.__exit__(None,None,None)"
        first=subprocess.Popen([sys.executable,'-c',script,str(self.firewall.LOCK)],stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True)
        second=None
        try:
            self.assertEqual(first.stdout.readline().strip(),'entered')
            second=subprocess.Popen([sys.executable,'-c',script,str(self.firewall.LOCK)],stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True)
            self.assertEqual(select.select([second.stdout],[],[],0.15)[0],[])
            first.stdin.write('done\n');first.stdin.flush();first.wait(timeout=3)
            self.assertEqual(second.stdout.readline().strip(),'entered')
            second.stdin.write('done\n');second.stdin.flush();second.wait(timeout=3)
            self.assertEqual(first.returncode,0);self.assertEqual(second.returncode,0)
        finally:
            for process in [first,second]:
                if process is not None:
                    if process.poll() is None: process.kill();process.wait()
                    process.stdin.close();process.stdout.close()
    def test_rule_arguments_keep_exact_ipv4_scope_and_no_shell(self):
        self.assertEqual(self.firewall.arguments(self.shape),['allow','in','proto','tcp','from','192.168.6.1','to','192.168.6.18','port','443','comment','orbit:route-7-lan'])

if __name__=='__main__':unittest.main()
