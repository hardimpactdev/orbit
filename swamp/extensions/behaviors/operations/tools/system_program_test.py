"""Native Tools policy and account regression tests; no fleet or package effects."""
import io
import os
import tarfile
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).parent

def raw(name):
    text = (ROOT / name).read_text()
    return text.split('String.raw`', 1)[1].rsplit('`;', 1)[0]

PROGRAM = ''.join(raw(name) for name in ('system_program.ts', 'materialize_program.ts')) + raw('local.ts')
MODULE = types.ModuleType('native_tools_test')
exec(compile(PROGRAM, 'native-tools', 'exec'), MODULE.__dict__)
M = MODULE

class Host:
    def __init__(self, replies=(), manager='apt', platform='linux', user='candidate'):
        self.calls = []
        self.replies = list(replies)
        self.node = dict(manager=manager, platform=platform, architecture='x86_64', user=user)
        self.account = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_name=user, pw_dir='/home/'+user)
        self.home = self.account.pw_dir
    def run(self, argv, **options):
        self.calls.append((argv, options))
        return self.replies.pop(0) if self.replies else (0, '', '')

class NativeTools(unittest.TestCase):







    def test_candidate_account_is_resolved_without_worker_or_persisted_identity(self):
        candidate=types.SimpleNamespace(pw_uid=2101,pw_gid=2102,pw_name='candidate',pw_dir='/home/candidate')
        with patch.object(M.pwd,'getpwnam',return_value=candidate),patch.object(M.os,'geteuid',return_value=0):
            for manager in ('composer','vp'):
                host=M.Host({'manager':manager,'platform':'linux','user':'candidate'})
                self.assertEqual(host.account,candidate);self.assertEqual(host.env['HOME'],'/home/candidate')
            with patch.object(M.os,'geteuid',return_value=1000),self.assertRaises(M.Refusal):M.Host({'manager':'vp','platform':'linux','user':'candidate'})
        node={'node_id':17,'manager':'vp','platform':'linux','architecture':'x86_64','user':'candidate'}
        value={'name':'tool.materialize-vp','input':{'user':'prior'},'node':node}
        with patch.object(M,'Host',side_effect=AssertionError('No effect')),self.assertRaises(M.Refusal):M.dispatch(value)
        self.assertEqual(node['user'],'candidate')

    def test_manager_subprocess_drops_to_candidate_uid_and_home(self):
        candidate=types.SimpleNamespace(pw_uid=2101,pw_gid=2102,pw_name='candidate',pw_dir='/home/candidate')
        child=types.SimpleNamespace(stdout=io.BytesIO(),stderr=io.BytesIO(),wait=lambda **kw:0,poll=lambda:0)
        selector=types.SimpleNamespace(register=lambda *args:None,get_map=lambda:{},close=lambda:None)
        with patch.object(M.pwd,'getpwnam',return_value=candidate),patch.object(M.os,'geteuid',return_value=0),patch.object(M.os,'getgrouplist',return_value=[2102]),patch.object(M.selectors,'DefaultSelector',return_value=selector),patch.object(M.subprocess,'Popen',return_value=child) as launch:
            host=M.Host({'manager':'composer','platform':'linux','user':'candidate'})
            host.run(['/usr/bin/composer','--version'])
            args,options=launch.call_args
            self.assertEqual(options['user'],2101);self.assertEqual(options['group'],2102)
            self.assertEqual(options['cwd'],'/home/candidate');self.assertEqual(options['env']['HOME'],'/home/candidate')
            self.assertNotIn('shell',options);self.assertNotIn('SUDO_USER',options['env'])

    def test_dispatch_rejects_foreign_managers_and_unsupported_platforms_before_effects(self):
        node=dict(manager='apt',user='candidate',platform='linux',architecture='x86_64')
        with patch.object(M,'Host',side_effect=AssertionError('No effect')):
            for name,data in [('arbitrary',{}),('tool.vp-install',{'package':'semver'}),('tool.apt-install',{'package':'hello','argv':[]}),('tool.materialize-composer',{'user':'candidate'})]:
                with self.assertRaises(M.Refusal):M.dispatch({'name':name,'input':data,'node':node})
            with self.assertRaises(M.Refusal):M.dispatch({'name':'tool.apt-install','input':{'package':'hello'},'node':{**node,'platform':'macos'}})



    def test_scope_observations_refuse_foreign_ownership_and_symlinks(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'scope';path.mkdir()
            self.assertTrue(M.owned(path,os.getuid(),os.getgid()))
            with self.assertRaises(M.Refusal):M.owned(path,os.getuid()+1)
            alias=Path(tmp)/'alias';alias.symlink_to(path)
            with self.assertRaises(M.Refusal):M.owned(alias,os.getuid())
            target=path/'manifest';target.write_text('{}')
            linked=path/'linked';linked.symlink_to(target)
            with self.assertRaises(OSError):M.read(linked,os.getuid())
            with self.assertRaises(M.Refusal):M.read(target,os.getuid(),limit=1)

    def test_first_use_refuses_conflicts_before_installing_prerequisites(self):
        host=Host(manager='composer')
        with patch.object(M,'owned',side_effect=M.Refusal('tool.scope_conflict')):
            with self.assertRaises(M.Refusal):M.composer_materialize(host,{'user':'candidate'})
        self.assertEqual(host.calls,[])
        host=Host(manager='vp')
        with self.assertRaises(M.Refusal):M.vp_materialize(host,{'user':'candidate','architecture':'amd64','tools':{}})
        self.assertEqual(host.calls,[])

    def test_vp_artifact_rejects_archive_escape_before_publication(self):
        with tempfile.TemporaryDirectory() as tmp:
            base=Path(tmp);archive=base/'bad.tar'
            with tarfile.open(archive,'w') as tar:
                info=tarfile.TarInfo('../escaped');info.size=4;tar.addfile(info,io.BytesIO(b'nope'))
            with self.assertRaises(tarfile.FilterError):M.extract(archive,base/'target',Host().account)
            self.assertFalse((base/'escaped').exists())
        for location in ('http://example.test/x','https://user:pass@example.test/x','file:///tmp/x','https://example.test/x#fragment'):
            with self.assertRaises(M.Refusal):M.https(location)

if __name__=='__main__':unittest.main()
