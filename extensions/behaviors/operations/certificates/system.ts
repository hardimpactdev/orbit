import { runLocalProgram } from "../routes/local-program.ts";

/** Certificate execution belongs to the placed Swamp worker. Private keys stay on that Node. */
export const certificateProgram = String.raw`
import datetime, grp, hashlib, json, os, pathlib, re, shutil, stat, subprocess, sys
P = pathlib.Path
CADDY = P('/etc/caddy/orbit-certificates')
ANCHOR = P('/usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
BUNDLE = P('/etc/ssl/certs/ca-certificates.crt')

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

def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def public_key(path, cert=False):
    return run('openssl', 'x509' if cert else 'pkey', '-in', path, '-pubkey', '-noout') if cert else run('openssl', 'pkey', '-in', path, '-pubout')
def directories(path, uid, gid, mode):
    path.mkdir(parents=True, exist_ok=True)
    os.chmod(path, mode); os.chown(path, uid, gid)
def roots(data):
    if not re.fullmatch(r'(app-instance-[1-9][0-9]*(-hostname-change)?|route-[1-9][0-9]*(-router(-hostname-change)?|-ingress)?)', data['scope']): raise ValueError('certificate.leaf_failed')
    home=P(data['home'])
    if not home.is_absolute() or any(p in ('.', '..') for p in home.parts): raise ValueError('certificate.leaf_failed')
    return home/'.orbit/certificates'/data['scope'], CADDY/data['scope']
def trust(root, expected, cert):
    if digest(root) != expected: raise ValueError('certificate.leaf_failed')
    if not ANCHOR.exists() or digest(ANCHOR) != expected:
        staged=ANCHOR.with_name('.orbit-root-'+str(os.getpid()))
        shutil.copyfile(root, staged); os.chmod(staged,0o644); os.chown(staged,0,0); os.replace(staged,ANCHOR)
        run('update-ca-certificates')
    run('openssl','verify','-CAfile',BUNDLE,cert)
def valid(current, caddy, data):
    try:
        key, cert, root = [current/name for name in ('key.pem','cert.pem','root.pem')]
        if digest(root) != data['root_hash']: return False
        run('openssl','verify','-CAfile',root,cert)
        run('openssl','x509','-in',cert,'-noout','-checkend','2592000')
        if 'does NOT match' in run('openssl','x509','-in',cert,'-noout','-checkhost',data['domain']): return False
        dates=run('openssl','x509','-in',cert,'-noout','-dates').splitlines()
        parse=lambda value:datetime.datetime.strptime(value.split('=',1)[1],'%b %d %H:%M:%S %Y GMT')
        if not 34214400 <= (parse(dates[1])-parse(dates[0])).total_seconds() <= 34387200: return False
        if '2048 bit' not in run('openssl','pkey','-in',key,'-text_pub','-noout'): return False
        expected={'basicConstraints':'X509v3 Basic Constraints: critical CA:FALSE','keyUsage':'X509v3 Key Usage: critical Digital Signature, Key Encipherment','extendedKeyUsage':'X509v3 Extended Key Usage: TLS Web Server Authentication','subjectAltName':'X509v3 Subject Alternative Name: DNS:'+data['domain']}
        for extension, value in expected.items():
            if ' '.join(run('openssl','x509','-in',cert,'-noout','-ext',extension).split()) != value: return False
        return public_key(key)==public_key(cert,True)==public_key(caddy/'key.pem') and (caddy/'cert.pem').read_bytes()==cert.read_bytes()
    except (ValueError, OSError, subprocess.SubprocessError): return False
def request(data, account):
    root,caddy=roots(data)
    if valid(root/'current',caddy/'current',data):
        trust(root/'current/root.pem',data['root_hash'],root/'current/cert.pem')
        return {'state':'current'}
    uid,gid,_=account
    versions=root/'versions'; version=data['version']
    if not re.fullmatch('[a-f0-9]{16}',version) or (versions/version).exists(): raise ValueError('certificate.leaf_failed')
    for directory in (P(data['home'])/'.orbit', P(data['home'])/'.orbit/certificates',root,versions): directories(directory,uid,gid,0o700)
    candidate=versions/(version+'.candidate')
    for other in versions.glob('*.candidate'):
        if other != candidate: shutil.rmtree(other)
    directories(candidate,uid,gid,0o700)
    key=candidate/'key.pem'
    if key.exists():
        if key.is_symlink() or '2048 bit' not in run('openssl','pkey','-in',key,'-text_pub','-noout'): raise ValueError('certificate.leaf_failed')
    else: run('openssl','genrsa','-out',key,'2048')
    os.chmod(key,0o600); os.chown(key,uid,gid)
    csr=candidate/'request.pem'
    run('openssl','req','-new','-key',key,'-subj','/CN='+data['domain'],'-out',csr)
    os.chmod(csr,0o600); os.chown(csr,uid,gid)
    return {'state':'request','request':csr.read_text()}
def link(directory,version,target,owner=None):
    staged=directory/('.current-'+version)
    staged.unlink(missing_ok=True); staged.symlink_to(target)
    if owner: os.chown(staged,*owner,follow_symlinks=False)
    os.replace(staged,directory/'current')
def publish(data, account):
    root,caddy=roots(data); version=data['version']; candidate=root/'versions'/(version+'.candidate')
    uid,gid,_=account
    if not candidate.is_dir(): raise ValueError('certificate.leaf_failed')
    for name,value in [('cert.pem',data['certificate']),('root.pem',data['root_certificate'])]:
        path=candidate/name; path.write_text(value); os.chmod(path,0o644); os.chown(path,uid,gid)
    candidate.joinpath('request.pem').unlink(missing_ok=True)
    cert,key,root_pem=[candidate/name for name in ('cert.pem','key.pem','root.pem')]
    if digest(root_pem)!=data['root_hash'] or public_key(key)!=public_key(cert,True): raise ValueError('certificate.leaf_failed')
    if 'does NOT match' in run('openssl','x509','-in',cert,'-noout','-checkhost',data['domain']): raise ValueError('certificate.leaf_failed')
    run('openssl','verify','-CAfile',root_pem,cert); trust(root_pem,data['root_hash'],cert)
    published=root/'versions'/version; os.rename(candidate,published); link(root,version,published,(uid,gid))
    caddy_group=grp.getgrnam('caddy').gr_gid
    caddy_candidate=caddy/'versions'/(version+'.candidate'); caddy_published=caddy/'versions'/version
    for directory in (CADDY,caddy,caddy/'versions',caddy_candidate): directories(directory,0,caddy_group,0o750)
    for name,mode in [('key.pem',0o640),('cert.pem',0o644)]:
        target=caddy_candidate/name; shutil.copyfile(published/name,target); os.chmod(target,mode); os.chown(target,0,caddy_group)
    if caddy_published.exists(): shutil.rmtree(caddy_published)
    os.rename(caddy_candidate,caddy_published); link(caddy,version,caddy_published)
    if subprocess.run(['systemctl','is-active','--quiet','caddy'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0: run('systemctl','reload','caddy')
    return {}
def execute(action,data):
    account=local_account(data)
    data={**data,'home':account[2]}
    root,caddy=roots(data)
    if action=='certificate.request-leaf': return request(data,account)
    if action=='certificate.publish-leaf': return publish(data,account)
    if action=='certificate.inspect-leaf': return {'present':(root/'current/cert.pem').is_file() and (caddy/'current/cert.pem').is_file()}
    if action=='certificate.remove-leaf':
        for path in (root,caddy):
            if path.exists(): shutil.rmtree(path)
        return {}
    raise ValueError('certificate.leaf_failed')
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
