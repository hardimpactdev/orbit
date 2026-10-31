import { z } from "npm:zod@4.4.3";
import { caddyProgram } from "../routes/caddy-program.ts";
import { runLocalProgram } from "../routes/local-program.ts";

/** Role/extension pair transaction on the same host lock as the whole Caddy publication. */
export const RoleCertificateExtension = String.raw`
import ctypes, secrets
OWNER_UID=0
class CertificateRefusal(Exception): pass
def certificate_scope(raw):
    if not re.fullmatch(r'/etc/caddy/orbit-[a-z][a-z0-9-]{0,63}-cert-current',raw): raise CertificateRefusal('caddy.certificate_input_invalid')
    return ROOT/Path(raw).name
def certificate_names(cert,key): return re.fullmatch(r'[a-z][a-z0-9_-]{0,63}\.pem',cert) is not None and key==cert[:-4]+'.key'
def certificate_parents():
    for path in [ROOT.parent,ROOT]:
        if not path.exists(): return False
        meta=path.lstat()
        if not stat.S_ISDIR(meta.st_mode) or meta.st_uid!=OWNER_UID or meta.st_mode&0o022: raise CertificateRefusal('caddy.certificate_unsafe')
    return True
def previous_pair(path,gid):
    if not exists(path): return None
    meta=path.lstat()
    if not stat.S_ISDIR(meta.st_mode) or meta.st_uid!=OWNER_UID or meta.st_gid!=gid or stat.S_IMODE(meta.st_mode)!=0o750: raise CertificateRefusal('caddy.certificate_unsafe')
    names=sorted(p.name for p in path.iterdir())
    if len(names)!=2 or not certificate_names(names[1],names[0]): raise CertificateRefusal('caddy.certificate_unsafe')
    result={}
    for name in names:
        meta=(path/name).lstat()
        if not stat.S_ISREG(meta.st_mode) or meta.st_uid!=OWNER_UID or meta.st_gid!=gid or stat.S_IMODE(meta.st_mode)!=0o640 or meta.st_size>65536: raise CertificateRefusal('caddy.certificate_unsafe')
        fd=os.open(path/name,os.O_RDONLY|os.O_NOFOLLOW)
        with os.fdopen(fd,'rb') as file: result[name]=file.read(65537)
        if len(result[name])>65536: raise CertificateRefusal('caddy.certificate_unsafe')
    return result
def certificate_write(path,value,gid):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o640)
    with os.fdopen(fd,'w') as file:
        os.fchown(file.fileno(),OWNER_UID,gid);os.fchmod(file.fileno(),0o640);file.write(value)
def exchange_pair(a,b):
    libc=ctypes.CDLL(None,use_errno=True)
    if libc.renameat2(-100,os.fsencode(a),-100,os.fsencode(b),2)!=0: raise OSError(ctypes.get_errno(),'certificate exchange failed')
def certificate_active():
    code=command('/usr/bin/systemctl','is-active','--quiet','caddy').returncode
    if code not in (0,3,4): raise CertificateRefusal('caddy.certificate_failed')
    return code==0
def certificate_reload(): return service('reload-or-restart')
def certificate_valid(cert,key):
    public=command('/usr/bin/openssl','x509','-in',cert,'-noout','-pubkey')
    private=command('/usr/bin/openssl','pkey','-in',key,'-pubout')
    return public.returncode==private.returncode==0 and public.stdout==private.stdout

def role_certificate(payload):
    current=certificate_scope(payload['directory'])
    if not certificate_parents():
        if payload['operation']=='remove': return {}
        raise CertificateRefusal('caddy.certificate_unsafe')
    if payload['operation']=='remove' and not exists(current): return {}
    gid=grp.getgrnam('caddy').gr_gid;previous=previous_pair(current,gid)
    if payload['operation']=='remove':
        if previous is not None: shutil.rmtree(current)
        return {}
    if payload['operation']!='install' or payload['service']!='caddy' or not certificate_names(payload['certificate_file'],payload['key_file']): raise CertificateRefusal('caddy.certificate_input_invalid')
    values={payload['certificate_file']:payload['certificate'],payload['key_file']:payload['key']}
    if any(not isinstance(v,str) or not v or len(v)>65536 or '\x00' in v for v in values.values()): raise CertificateRefusal('caddy.certificate_input_invalid')
    if previous is not None and set(previous)!=set(values): raise CertificateRefusal('caddy.certificate_unsafe')
    candidate=current.with_name(current.name+'.candidate.'+secrets.token_hex(8));candidate.mkdir(mode=0o750);os.chown(candidate,OWNER_UID,gid);candidate.chmod(0o750);recovery_failed=False
    try:
        for name,value in values.items(): certificate_write(candidate/name,value,gid)
        if not certificate_valid(candidate/payload['certificate_file'],candidate/payload['key_file']): raise CertificateRefusal('caddy.certificate_invalid')
        if previous=={name:value.encode() for name,value in values.items()}: return {'changed':False}
        active=certificate_active()
        if previous is None: candidate.rename(current)
        else: exchange_pair(candidate,current)
        if active and not certificate_reload():
            try:
                if previous is None: current.rename(candidate)
                else: exchange_pair(candidate,current)
            except OSError:
                recovery_failed=True;raise CertificateRefusal('caddy.certificate_recovery_failed')
            if not certificate_reload():
                recovery_failed=True;raise CertificateRefusal('caddy.certificate_recovery_failed')
            raise CertificateRefusal('caddy.certificate_failed')
        return {'changed':True}
    finally:
        if not recovery_failed and candidate.exists(): shutil.rmtree(candidate)
`;
export const RoleCertificateProgram = '__name__="orbit_role_certificate"\n' +
  caddyProgram + RoleCertificateExtension + String.raw`
try:
    payload=json.load(sys.stdin)
    with publication_lock(): result=role_certificate(payload)
except CertificateRefusal as error: result={'error_code':str(error)}
except Refusal as error: result={'error_code':'caddy.publication_lock_failed' if error.stage=='lock' else 'caddy.certificate_failed'}
except Exception: result={'error_code':'caddy.certificate_failed'}
print(json.dumps(result))
`;
const Directory = z.string().regex(
  /^\/etc\/caddy\/orbit-[a-z][a-z0-9-]{0,63}-cert-current$/,
);
const Install = z.object({
  directory: Directory,
  certificate_file: z.string(),
  key_file: z.string(),
  certificate: z.string().max(65536),
  key: z.string().max(65536),
  service: z.literal("caddy"),
}).strict();
const Remove = z.object({ directory: Directory }).strict();
const Refusal = z.object({
  error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
}).strict();
/** Call only inside the owner's private-material callback; never return the pair from a step. */
export async function installRoleCertificate(
  raw: unknown,
  invoke = runLocalProgram,
  signal?: AbortSignal,
) {
  return z.union([z.object({ changed: z.boolean() }).strict(), Refusal]).parse(
    await invoke(RoleCertificateProgram, {
      operation: "install",
      ...Install.parse(raw),
    }, signal),
  );
}
export async function removeRoleCertificate(
  raw: unknown,
  invoke = runLocalProgram,
  signal?: AbortSignal,
) {
  return z.union([z.object({}).strict(), Refusal]).parse(
    await invoke(RoleCertificateProgram, {
      operation: "remove",
      ...Remove.parse(raw),
    }, signal),
  );
}
