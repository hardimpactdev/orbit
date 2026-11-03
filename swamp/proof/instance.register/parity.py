"""Reviewed registration: renamed linked source, opt-in setup/Process/Route, plain record-only adoption."""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
checkout = operation['input']['checkout']
domain = 'r-' + os.environ['PROOF_RUN'] + '.proof-source.test'


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, timeout=30, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


def gateway(expression):
    reply = subprocess.run(['runuser', '-u', 'orbit', '--', 'env', 'HOME=/tmp', 'XDG_CONFIG_HOME=/tmp',
                            'php', 'artisan', 'tinker', '--execute', expression], cwd='apps/gateway',
                           capture_output=True, text=True, check=True, timeout=30)
    return json.loads(reply.stdout.strip())


check('proof-private-' not in outputs and 'PRIVATE KEY-----' not in outputs, 'private content reached native history')
check(target('stat', '-c', '%a %U', checkout + '/.env').stdout.strip() == '600 orbit', 'setup created the private environment')
check(target('runuser', '-u', 'orbit', '--', 'git', '-C', checkout, 'branch', '--show-current').stdout.strip() == 'renamed-' + os.environ['PROOF_RUN'], 'the renamed worktree branch was preserved')
check(operation['result']['commit'] == target('runuser', '-u', 'orbit', '--', 'git', '-C', checkout, 'rev-parse', 'HEAD').stdout.strip(), 'the recorded commit matches the checkout')
instance = gateway("$i=App\\Models\\Instance::findOrFail(" + str(operation['target_id']) + "); echo json_encode(['status'=>$i->status->value,'layout'=>$i->source_layout,'routes'=>$i->routes->map(fn($r)=>['status'=>$r->status->value,'published'=>$r->sites_published])->all()]);")
check(instance['status'] == 'active' and instance['layout'] == 'worktree', 'the linked Instance is active')
check(instance['routes'] == [{'status': 'active', 'published': True}], 'the Route is active and published')
plain = gateway("$i=App\\Models\\Instance::where('checkout_path'," + json.dumps('/home/orbit/apps/proof-source/plain-' + os.environ['PROOF_RUN']) + ")->firstOrFail(); echo json_encode(['status'=>$i->status->value,'operations'=>App\\Models\\Operation::where('target_type','instance')->where('target_id',$i->id)->count()]);")
check(plain == {'status': 'active', 'operations': 0}, 'plain registration is active without an Operation or run')
for member in operation['input']['members']:
    check(target('systemctl', 'is-active', member['input']['name']).stdout.strip() == 'active', 'the development Process is active')
listen = target('ss', '-ltnH', 'sport = :443').stdout.split()[3]
address = listen.rsplit(':', 1)[0]
response = target('curl', '--noproxy', '*', '--fail', '--silent', '--show-error', '--max-time', '10',
                  '--cacert', '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt',
                  '--resolve', domain + ':443:' + address, 'https://' + domain + '/proof.txt')
check(response.returncode == 0 and response.stdout.strip() == 'registered-worktree', 'the registered domain answers over verified TLS')
print('parity: linked worktree unchanged; setup env, Process and verified TLS ready; plain HTTP 201 starts no Operation')
