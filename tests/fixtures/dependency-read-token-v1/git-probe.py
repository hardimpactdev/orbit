#!/usr/bin/env python3
import os,sys,subprocess,pathlib
for option in ['core.hooksPath=/dev/null','core.fsmonitor=false','credential.helper=','credential.useHttpPath=true','http.followRedirects=false','protocol.allow=never','protocol.https.allow=always']:
 assert any(sys.argv[i:i+2]==['-c',option] for i in range(len(sys.argv)-1))
assert os.environ['HOME']==os.environ['XDG_CONFIG_HOME']
assert os.environ['GIT_CONFIG_NOSYSTEM']=='1'
assert os.environ['GIT_CONFIG_GLOBAL']=='/dev/null'
assert 'GIT_CONFIG_COUNT' not in os.environ
assert 'GH_TOKEN' not in os.environ
assert 'ephemeral_sentinel' not in ' '.join(sys.argv)
helper=os.environ['GIT_ASKPASS']
assert subprocess.check_output([helper,"Password for 'https://x-access-token@github.com/nckrtl/website.git': "],text=True).strip()=='ephemeral_sentinel'
assert subprocess.run([helper,"Password for 'https://other.test/repo': "],capture_output=True).returncode!=0
assert not any('ephemeral_sentinel' in p.read_text() for p in pathlib.Path(os.environ['HOME']).iterdir() if p.is_file())
print(os.environ['HOME'])
