import { caddyProgram } from "../routes/caddy-program.ts";
import { runLocalProgram } from "../routes/local-program.ts";
import { Applied, Refusal, Request, Signed } from "./input.ts";
import { RoleLeaf } from "./role-input.ts";
import { RoleCertificateExtension } from "./role-publication.ts";

/** Fixed role paths and Node-local keys; the existing signer owns all CA policy. */
export const RoleActionsExtension = String.raw`
import base64
KEY_ROOT=Path('/var/lib/orbit/role-certificates')
def role_paths(data):
    role=data['scope'].removeprefix('role-')
    if role not in ('gateway','metrics','websocket','analytics'): raise CertificateRefusal('certificate.role_input_invalid')
    if not re.fullmatch('[a-f0-9]{16}',data['version']) or not re.fullmatch('[a-f0-9]{64}',data['root_hash']): raise CertificateRefusal('certificate.role_input_invalid')
    if len(data['domain'])>253 or not re.fullmatch(r'(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?',data['domain']): raise CertificateRefusal('certificate.role_input_invalid')
    directory='/etc/caddy/orbit-'+('' if role=='gateway' else role+'-')+'cert-current'
    return role,certificate_scope(directory),directory

def role_checked(*args):
    result=command('/usr/bin/openssl',*args)
    if result.returncode: raise CertificateRefusal('certificate.role_failed')
    return result.stdout.decode()

def role_valid(current,data):
    try:
        cert=current/(data['scope'].removeprefix('role-')+'.pem');key=cert.with_suffix('.key');root=current/'root-ca.pem'
        if hashlib.sha256(root.read_bytes()).hexdigest()!=data['root_hash']: return False
        role_checked('verify','-CAfile',root,cert)
        role_checked('x509','-in',cert,'-noout','-checkend','2592000')
        if 'does NOT match' in role_checked('x509','-in',cert,'-noout','-checkhost',data['domain']): return False
        return certificate_valid(cert,key)
    except (CertificateRefusal,OSError): return False

def role_workspace(data):
    paths=[KEY_ROOT.parent.parent,KEY_ROOT.parent,KEY_ROOT,KEY_ROOT/data['scope'],KEY_ROOT/data['scope']/data['version']]
    for path in paths:
        if not exists(path): path.mkdir(mode=0o700)
        meta=path.lstat()
        if not stat.S_ISDIR(meta.st_mode) or meta.st_uid!=OWNER_UID or meta.st_mode&0o022: raise CertificateRefusal('certificate.role_unsafe')
        if path==KEY_ROOT or path.is_relative_to(KEY_ROOT):
            if stat.S_IMODE(meta.st_mode)!=0o700: raise CertificateRefusal('certificate.role_unsafe')
    return paths[-1]

def role_key(path):
    meta=path.lstat()
    if not stat.S_ISREG(meta.st_mode) or meta.st_uid!=OWNER_UID or meta.st_nlink!=1 or stat.S_IMODE(meta.st_mode)!=0o600 or meta.st_size>8192: raise CertificateRefusal('certificate.role_unsafe')
    if '2048 bit' not in role_checked('pkey','-in',path,'-text_pub','-noout'): raise CertificateRefusal('certificate.role_unsafe')

def role_request(data):
    role,current,_=role_paths(data)
    if not certificate_parents(): raise CertificateRefusal('caddy.certificate_unsafe')
    gid=grp.getgrnam('caddy').gr_gid
    previous=previous_pair(current,gid)
    if previous is not None and role_valid(current,data): return {'state':'current'}
    workspace=role_workspace(data);key=workspace/'key.pem'
    if not exists(key):
        fd=os.open(key,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);os.close(fd)
        role_checked('genrsa','-out',key,'2048')
    role_key(key)
    request=role_checked('req','-new','-key',key,'-subj','/CN='+data['domain'])
    return {'state':'request','request_base64':base64.b64encode(request.encode()).decode()}

def role_publish(data):
    role,current,directory=role_paths(data)
    if not certificate_parents(): raise CertificateRefusal('caddy.certificate_unsafe')
    previous=previous_pair(current,grp.getgrnam('caddy').gr_gid)
    if data['request']['state']=='current':
        if previous is None or not role_valid(current,data): raise CertificateRefusal('certificate.role_not_current')
        return {'applied':True}
    certificate=base64.b64decode(data['signed']['certificate_base64'],validate=True).decode()
    root=base64.b64decode(data['signed']['root_certificate_base64'],validate=True).decode()
    if hashlib.sha256(root.encode()).hexdigest()!=data['root_hash']: raise CertificateRefusal('certificate.role_root_mismatch')
    if previous is not None and previous.get(role+'.pem')==certificate.encode() and previous.get('root-ca.pem')==root.encode() and role_valid(current,data): return {'applied':True}
    workspace=role_workspace(data);key=workspace/'key.pem';role_key(key)
    for name,value in [('certificate.pem',certificate),('root.pem',root)]:
        path=workspace/name
        if exists(path):
            meta=path.lstat()
            if not stat.S_ISREG(meta.st_mode) or meta.st_uid!=OWNER_UID or meta.st_nlink!=1: raise CertificateRefusal('certificate.role_unsafe')
            path.unlink()
        certificate_write(path,value,os.getgid());path.chmod(0o600)
    cert=workspace/'certificate.pem';ca=workspace/'root.pem'
    role_checked('verify','-CAfile',ca,cert)
    role_checked('x509','-in',cert,'-noout','-checkend','2592000')
    if 'does NOT match' in role_checked('x509','-in',cert,'-noout','-checkhost',data['domain']) or not certificate_valid(cert,key): raise CertificateRefusal('certificate.role_signature_mismatch')
    # The key is read only inside this fixed Node-local transaction.
    role_certificate(dict(operation='install',directory=directory,certificate_file=role+'.pem',key_file=role+'.key',certificate=certificate,key=key.read_text(),root_certificate=root,service='caddy'))
    shutil.rmtree(workspace)
    return {'applied':True}
`;
export const RoleActionsProgram = '__name__="orbit_role_actions"\n' +
  caddyProgram + RoleCertificateExtension + RoleActionsExtension + String.raw`
try:
    data=json.load(sys.stdin)
    with publication_lock():
        if data['operation']=='request': result=role_request(data)
        elif data['operation']=='publish': result=role_publish(data)
        else: raise CertificateRefusal('certificate.role_input_invalid')
except CertificateRefusal as error: result={'error_code':str(error)}
except Refusal as error: result={'error_code':'caddy.publication_lock_failed' if error.stage=='lock' else 'certificate.role_failed'}
except Exception: result={'error_code':'certificate.role_failed'}
print(json.dumps(result))
`;

export function roleCertificateActions(invoke = runLocalProgram) {
  return {
    request: async (raw: unknown, signal?: AbortSignal) => {
      const leaf = RoleLeaf.parse(raw);
      return Request.parse(
        await invoke(RoleActionsProgram, {
          operation: "request",
          ...leaf,
        }, signal),
      );
    },
    publish: async (
      raw: unknown,
      rawRequest: unknown,
      rawSigned: unknown,
      signal?: AbortSignal,
    ) => {
      const leaf = RoleLeaf.parse(raw), request = Request.parse(rawRequest);
      if ("error_code" in request) return request;
      const signed = Signed.parse(rawSigned);
      if ("error_code" in signed) return Refusal.parse(signed);
      if (request.state === "request" && !("certificate_base64" in signed)) {
        throw new Error("Public role certificate signature missing");
      }
      return Applied.parse(
        await invoke(RoleActionsProgram, {
          operation: "publish",
          ...leaf,
          request,
          signed,
        }, signal),
      );
    },
  };
}
