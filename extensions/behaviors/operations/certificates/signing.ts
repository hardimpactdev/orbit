import { z } from "npm:zod@4.4.3";
import { Leaf } from "./input.ts";
import { runLocalProgram } from "../routes/local-program.ts";

export const CertificateRequestRecord = z.union([
  z.object({
    node_id: z.number().int().positive(),
    scope: z.string(),
    state: z.literal("current"),
  }).strict(),
  z.object({
    node_id: z.number().int().positive(),
    scope: z.string(),
    state: z.literal("request"),
    request_base64: z.string().max(32768),
  }).strict(),
]);
export const CertificateSignedRecord = z.object({
  node_id: z.number().int().positive(),
  scope: z.string(),
  certificate_base64: z.string().max(32768),
  root_certificate_base64: z.string().max(32768),
}).strict();
const Batch = z.object({
  certificates: z.array(CertificateSignedRecord).max(256),
}).strict();
const Ca = z.object({
  certificate: z.string().max(32768),
  private_key: z.string().max(32768),
}).strict();
export type CertificateCaVault = {
  vaultService: {
    get(vault: string, key: string, reason: string): Promise<string>;
  };
  vaultName: string;
  secretKey: string;
};

/** The private CA is read inside the control step, never in workflow arguments or resources. */
export const certificateSigningProgram = String.raw`
import base64, hashlib, json, os, re, secrets, subprocess, sys
class Refusal(Exception): pass
def run(args,input=None,fds=()):
    result=subprocess.run(['openssl',*args],input=input,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,pass_fds=fds,timeout=45)
    if result.returncode: raise Refusal('app-dev.certificate_request_invalid')
    return result.stdout.decode()
def memory(value):
    fd=os.memfd_create('orbit-certificate',os.MFD_CLOEXEC);os.write(fd,value.encode());os.lseek(fd,0,os.SEEK_SET);return fd

def sign(data):
    ca=data['ca'];root=ca['certificate'];key=ca['private_key'];root_fd=key_fd=None;certificates=[]
    try:
        root_fd=memory(root);key_fd=memory(key);root_path='/proc/self/fd/'+str(root_fd);key_path='/proc/self/fd/'+str(key_fd);fds=(root_fd,key_fd)
        details=run(['pkey','-in',key_path,'-text_pub','-noout'],fds=fds)
        if '4096 bit' not in details or 'Modulus:' not in details: raise Refusal('app-dev.certificate_sign_failed')
        if 'CA:TRUE' not in run(['x509','-in',root_path,'-noout','-ext','basicConstraints'],fds=fds): raise Refusal('app-dev.certificate_sign_failed')
        run(['x509','-in',root_path,'-noout','-checkend','0'],fds=fds);run(['verify','-CAfile',root_path,root_path],fds=fds)
        if run(['pkey','-in',key_path,'-pubout'],fds=fds)!=run(['x509','-in',root_path,'-noout','-pubkey'],fds=fds): raise Refusal('app-dev.certificate_sign_failed')
        admitted=data['leaves'];seen=set()
        # Validate the entire public request batch before any signature.
        pending=[]
        for request in data['requests']:
            selector=(request['node_id'],request['scope']);options=[leaf for leaf in admitted if (leaf['node_id'],leaf['scope'])==selector]
            if selector in seen or not options: raise Refusal('certificate.request_invalid')
            seen.add(selector)
            if request['state']=='current': continue
            csr=base64.b64decode(request['request_base64'],validate=True)
            if not csr.startswith(b'-----BEGIN CERTIFICATE REQUEST-----') or b'PRIVATE KEY' in csr: raise Refusal('certificate.request_invalid')
            subject=next((line for line in run(['req','-noout','-verify','-subject','-nameopt','RFC2253'],input=csr).splitlines() if line.startswith('subject=')), '')
            domain=subject.removeprefix('subject=CN=')
            if len(domain)>253 or not re.fullmatch(r'(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?',domain): raise Refusal('certificate.request_invalid')
            leaf=next((leaf for leaf in options if leaf['domain']==domain and leaf['root_hash']==hashlib.sha256(root.encode()).hexdigest()),None)
            if leaf is None: raise Refusal('certificate.request_invalid')
            public=run(['req','-noout','-pubkey'],input=csr)
            details=run(['pkey','-pubin','-text','-noout'],input=public.encode())
            if '2048 bit' not in details or 'Modulus:' not in details: raise Refusal('app-dev.certificate_request_invalid')
            pending.append((leaf,csr))
        for leaf,csr in pending:
            extensions='[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:'+leaf['domain']+'\n'
            ext_fd=memory(extensions)
            try: certificate=run(['x509','-req','-in','/dev/stdin','-CA',root_path,'-CAkey',key_path,'-set_serial','0x'+secrets.token_hex(20),'-days','397','-sha256','-extfile','/proc/self/fd/'+str(ext_fd),'-extensions','leaf'],input=csr,fds=(*fds,ext_fd))
            finally: os.close(ext_fd)
            certificates.append(dict(node_id=leaf['node_id'],scope=leaf['scope'],certificate_base64=base64.b64encode(certificate.encode()).decode(),root_certificate_base64=base64.b64encode(root.encode()).decode()))
        return dict(certificates=certificates)
    finally:
        for fd in (root_fd,key_fd):
            if fd is not None: os.close(fd)
if __name__=='__main__':
    try: result=sign(json.load(sys.stdin))
    except Refusal as error: result=dict(error_code=str(error))
    except Exception: result=dict(error_code='app-dev.certificate_sign_failed')
    print(json.dumps(result))
`;

/** Call only from the placed control-209 signing step. Returns public material only. */
export async function signCertificateBatch(
  rawLeaves: unknown,
  rawRequests: unknown,
  custody: CertificateCaVault,
  signal?: AbortSignal,
  invoke = runLocalProgram,
) {
  const leaves = z.array(Leaf).max(256).parse(rawLeaves);
  const requests = z.array(CertificateRequestRecord).max(256).parse(
    rawRequests,
  );
  if (requests.every((r) => r.state === "current")) return { certificates: [] };
  signal?.throwIfAborted();
  try {
    const material = await custody.vaultService.get(
      custody.vaultName,
      custody.secretKey,
      "orbit-certificate-signing",
    );
    const ca = Ca.parse(JSON.parse(material));
    const response = await invoke(certificateSigningProgram, {
      leaves,
      requests,
      ca,
    }, signal);
    if (response.error_code !== undefined) {
      throw new Error("Certificate signing failed");
    }
    return Batch.parse(response);
  } catch {
    // Vault/provider errors can carry private values; only this fixed error leaves the step.
    throw new Error("app-dev.certificate_sign_failed");
  }
}
