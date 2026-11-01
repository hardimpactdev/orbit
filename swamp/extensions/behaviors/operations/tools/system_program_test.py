"""Native Tools policy and account regression tests; no fleet or package effects."""
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).parent

def raw(name):
    text = (ROOT / name).read_text()
    return text.split('String.raw`', 1)[1].rsplit('`;', 1)[0]

PROGRAM = ''.join(raw(name) for name in ('system_program.ts', 'homebrew_program.ts', 'materialize_program.ts')) + raw('local.ts')
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
    def test_package_arguments_refuse_shell_and_manager_options(self):
        valid = {'apt':['jq','g++','libfoo-dev'], 'composer':['psr/log','vendor/package'], 'vp':['semver','@scope/pkg'], 'brew':['hello','openssl@3','g++']}
        for manager, names in valid.items():
            for name in names:
                self.assertEqual(M.package(manager,name),name)
            for name in ('','-x','../x','https://x/y','x\nrm','x;id','x=1'):
                with self.assertRaises(M.Refusal):M.package(manager,name)

    def test_apt_candidates_preserve_debian_versions_and_reject_ambiguity(self):
        for raw, expected in [('  Candidate: 1:1.7.1-3ubuntu0.26.04.1\n','1:1.7.1-3ubuntu0.26.04.1'),('Candidate: (none)\n',None)]:
            host=Host([(0,raw,'')]);self.assertEqual(M.apt(host,'candidate',{'package':'jq'}),{'version':expected})
            self.assertEqual(host.calls[0][0],['/usr/bin/apt-cache','policy','--','jq'])
        for raw in ('','Candidate:1','Candidate: 1\nCandidate: 2','Candidate: ','Candidate: 1.0 unexpected','Candidate: 1\0hidden'):
            with self.subTest(raw=raw),self.assertRaises(M.Refusal):M.apt(Host([(0,raw,'')]),'candidate',{'package':'jq'})

    def test_apt_inventory_never_turns_unreadable_or_held_packages_into_absence(self):
        host=Host([(0,'jq\tinstall ok installed\t1.0\njq\tinstall ok installed\t2.0\nheld\thold ok installed\t1.0\nbad\tinstall ok installed\t1\0hidden\nold\tdeinstall ok config-files\t1.0\n','')])
        self.assertEqual(M.apt(host,'inventory',{})['packages'],[{'name':'jq','version':'1.0'},{'name':'jq','version':'2.0'},{'name':'held','version':None},{'name':'bad','version':None}])
        self.assertEqual(host.calls[0][0][2],'--showformat=${Package}\\t${Status}\\t${Version}\\n')
        with self.assertRaises(M.Refusal):M.apt(Host([(0,'jq\tbroken','')]),'inventory',{})

    def test_apt_install_and_update_preserve_dependencies(self):
        for verb in ('install','update'):
            host=Host();self.assertEqual(M.apt(host,verb,{'package':'hello'}),{})
            argv, options=host.calls[0]
            self.assertEqual(argv[-2:],['--','hello']);self.assertTrue(options['root'])
            self.assertNotIn('autoremove',argv);self.assertNotIn('--purge',argv)
            self.assertIn('--no-install-recommends',argv)

    def test_composer_dryrun_tracks_only_the_exact_root(self):
        for raw in ('  - Locking psr/log (3.0.2)\n  - Installing psr/log (3.0.2)\n','  - Upgrading psr/log (3.0.1 => 3.0.2)\n'):
            host=Host([(0,'',raw)],manager='composer')
            self.assertEqual(M.composer(host,'candidate',{'package':'psr/log','operation':'update'}),{'version':'3.0.2'})
            self.assertEqual(host.calls[0][0][:4],['/usr/bin/composer','global','update','psr/log'])
            self.assertIn('--dry-run',host.calls[0][0]);self.assertEqual(host.calls[0][1]['env']['COMPOSER_HOME'],'/opt/orbit/composer')
        for raw in ('  - Locking psr/log (1)\n  - Installing psr/log (2)','  - Locking psr/log broken','Nothing to install, update or remove\n  - Locking psr/log (1)'):
            with self.assertRaises(M.Refusal):M.composer(Host([(0,raw,'')],manager='composer'),'candidate',{'package':'psr/log','operation':'install'})

    def test_composer_installs_exact_wildcard_requirement_and_preserves_dependencies(self):
        host=Host(manager='composer');M.composer(host,'install',{'package':'psr/log'})
        self.assertEqual(host.calls[0][0][:4],['/usr/bin/composer','global','require','psr/log:*'])
        self.assertIn('--with-all-dependencies',host.calls[0][0])
        self.assertNotIn('root',host.calls[0][1])
        host=Host([(0,json.dumps({'installed':[{'name':'psr/log','version':None}]}),'')],manager='composer')
        self.assertEqual(M.composer(host,'inventory',{}),{'packages':[{'name':'psr/log','version':None}]})

    def test_vp_runtime_arguments_scope_and_incomplete_inventory(self):
        with patch.object(M,'vp_scope',lambda host:Path('/home/candidate/.vite-plus')):
            for verb,argv in [('install',['install','-g','semver','--node','lts']),('update',['update','-g','semver','--reinstall-node-mismatch'])]:
                host=Host(manager='vp');M.vp(host,verb,{'package':'semver'})
                self.assertEqual(host.calls[0][0],[str(Path('/home/candidate/.vite-plus/bin/vp')),*argv])
                self.assertEqual(host.calls[0][1]['env'],{'VP_HOME':'/home/candidate/.vite-plus'})
                with self.assertRaises(M.Refusal):M.vp(Host(manager='vp'),verb,{'package':'pnpm'})
            host=Host([(0,'[{"name":"semver","version":"1"},{"name":"semver","version":null}]','')],manager='vp')
            self.assertFalse(M.vp(host,'inventory',{})['scan_complete'])

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

    def test_brew_formula_requires_official_checksum_bottle_and_never_autoremove(self):
        sha='a'*64;formula={'name':'hello','full_name':'hello','tap':'homebrew/core','disabled':False,'versions':{'stable':'2.12.3','bottle':True},'bottle':{'stable':{'files':{'x86_64_linux':{'sha256':sha,'url':'https://ghcr.io/v2/homebrew/core/hello/blobs/sha256:'+sha}}}}}
        reply=json.dumps({'formulae':[formula],'casks':[]})
        with patch.object(M,'brew_scope',lambda host:Path('/home/linuxbrew/.linuxbrew')),patch.object(M,'brew_tag',lambda host:'x86_64_linux'):
            for verb,expected in [('install','install'),('update','upgrade')]:
                host=Host([(0,reply,''),(0,'','')],manager='brew');M.brew(host,verb,{'package':'hello'})
                argv=host.calls[-1][0];self.assertEqual(argv[1],expected);self.assertIn('--formula',argv)
                self.assertIn('--force-bottle',argv)
                self.assertNotIn('autoremove',argv);self.assertNotIn('services',argv)
                self.assertEqual(host.calls[-1][1]['env']['HOMEBREW_NO_INSTALL_CLEANUP'],'1')
            for change in ({'tap':'private/tap'},{'disabled':True},{'bottle':{}},{'versions':{'stable':None,'bottle':True}}):
                bad=json.dumps({'formulae':[{**formula,**change}],'casks':[]})
                with self.assertRaises(M.Refusal):M.brew(Host([(0,bad,'')],manager='brew'),'install',{'package':'hello'})

    def test_casks_refuse_privileged_artifacts_and_arbitrary_uninstall(self):
        safe={'token':'safe','full_token':'safe','tap':'homebrew/cask','url':'https://example.test/safe.zip','disabled':False,'sha256':'a'*64,'version':'1.0','artifacts':[{'binary':['safe']}]}
        self.assertEqual(M.assess_cask(safe,'safe',Path('/opt/homebrew'),'/Users/candidate'),'1.0')
        for artifacts in ([{'app':['Safe.app']}],[{'pkg':['installer.pkg']}],[{'binary':[{'target':'/usr/bin/safe'}]}],[{'uninstall':[{'script':'anything'}]},{'binary':['safe']}],[{'binary':['safe']},{'uninstall':[{'delete':'/Library/safe'}]}]):
            with self.subTest(artifacts=artifacts),self.assertRaises(M.Refusal):M.assess_cask({**safe,'artifacts':artifacts},'safe',Path('/opt/homebrew'),'/Users/candidate')
        for change in ({'tap':'thirdparty/tap'},{'sha256':'no_check'},{'version':'latest'},{'disabled':True}):
            with self.assertRaises(M.Refusal):M.assess_cask({**safe,**change},'safe',Path('/opt/homebrew'),'/Users/candidate')
        M.artifacts_check([{'binary':['safe']},{'uninstall':[{'delete':'~/Library/safe'}]},{'zap':{'trash':'/outside/ignored'}}],Path('/opt/homebrew'),'/Users/candidate')

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
        host=Host(manager='brew')
        with patch.object(M,'owned',side_effect=M.Refusal('tool.scope_conflict')):
            with self.assertRaises(M.Refusal):M.brew_materialize(host,{'user':'candidate'})
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
