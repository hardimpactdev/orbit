import { runLocalProgram } from "../routes/local-program.ts";

/** Retained residue withdrawal only; native children own request, signing and publication. */
export const certificateProgram = String.raw`
import json, os, pathlib, re, shutil, stat, subprocess, sys
P = pathlib.Path
CADDY = P('/etc/caddy/orbit-certificates')

def run(*args):
    result = subprocess.run([str(a) for a in args], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=45)
    if result.returncode: raise ValueError('certificate.leaf_failed')
    return result.stdout.decode()
def local_account(data):
    user=data.get('user')
    if not isinstance(user,str) or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}',user): raise ValueError('certificate.account_invalid')
    entries=run('/usr/bin/getent','passwd',user).splitlines()
    if len(entries)!=1: raise ValueError('certificate.account_invalid')
    fields=entries[0].split(':')
    if len(fields)!=7 or fields[0]!=user or not fields[2].isdigit() or not fields[3].isdigit(): raise ValueError('certificate.account_invalid')
    uid,gid=int(fields[2]),int(fields[3]);home=P(fields[5])
    if not fields[5].startswith('/') or any(part in ('','.','..') for part in fields[5][1:].split('/')) or any(char in fields[5] for char in ('\0','\r','\n')): raise ValueError('certificate.account_invalid')
    if data.get('home') is not None and data['home']!=fields[5]: raise ValueError('certificate.home_mismatch')
    metadata=home.lstat()
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid!=uid: raise ValueError('certificate.home_unsafe')
    return uid,gid,fields[5]
def roots(data):
    if not re.fullmatch(r'(app-instance-[1-9][0-9]*(-hostname-change)?|route-[1-9][0-9]*(-router(-hostname-change)?|-ingress)?)', data['scope']): raise ValueError('certificate.leaf_failed')
    home=P(data['home'])
    if not home.is_absolute() or any(p in ('.', '..') for p in home.parts): raise ValueError('certificate.leaf_failed')
    return home/'.orbit/certificates'/data['scope'], CADDY/data['scope']
def execute(action,data):
    if action!='certificate.remove-leaf': raise ValueError('certificate.leaf_failed')
    account=local_account(data)
    root,caddy=roots({**data,'home':account[2]})
    for path in (root,caddy):
        if path.exists(): shutil.rmtree(path)
    return {}
if __name__=='__main__':
    try:
        data=json.load(sys.stdin); result=execute(data['name'],data['input'])
    except Exception: result={'error_code':'certificate.leaf_failed'}
    print(json.dumps(result))
`;

export type CertificateSystemCall = {
  name: string;
  input: Record<string, unknown>;
};
export async function runCertificateSystem(
  call: CertificateSystemCall,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  return await runLocalProgram(certificateProgram, call, signal);
}
