import { z } from "npm:zod@4.4.3";
import { BootstrapInstallProgram } from "./bootstrap_program.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const Hash = z.string().regex(/^[a-f0-9]{64}$/);
const Delivery = z.string().max(8192).refine((value) => {
  try {
    const url = new URL(value);
    return url.protocol === "https:" && !!url.hostname && !url.username &&
      !url.password && !url.hash && [...value].every((character) =>
        character.charCodeAt(0) > 32 && character.charCodeAt(0) !== 127 &&
        character !== "\\"
      );
  } catch {
    return false;
  }
});
const Result = z.union([
  z.object({ changed: z.boolean() }).strict(),
  z.object({
    changed: z.literal(false),
    error_code: z.enum([
      "agent.release_unavailable",
      "agent.platform_unsupported",
      "node.bootstrap_install_failed",
    ]),
  }).strict(),
]);

/** Download on the placed effect Node, then reuse the canonical atomic installer. */
export const ObserverInstallProgram =
  `INSTALL_PROGRAM=${JSON.stringify(BootstrapInstallProgram)}\n` + String.raw`
import contextlib,hashlib,io,json,os,pathlib,platform,re,sys,tempfile,time,urllib.error,urllib.parse,urllib.request
MAX_BYTES=128*1024*1024
class Refusal(Exception):
 def __init__(self,code='node.bootstrap_install_failed'):self.code=code
def valid_url(value):
 if not isinstance(value,str) or len(value)>8192 or re.search(r'[\x00-\x20\x7f\\]',value):raise Refusal()
 try:
  u=urllib.parse.urlsplit(value)
  if u.scheme!='https' or not u.hostname or u.username is not None or u.password is not None or u.fragment:raise Refusal()
  u.port
 except ValueError:raise Refusal()
 return value
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,req,fp,code,msg,headers,newurl):return None
def open_url(url,timeout):
 opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
 try:return opener.open(urllib.request.Request(url,headers={'Accept-Encoding':'identity'}),timeout=timeout)
 except urllib.error.HTTPError as response:return response
def download(url,path,pin):
 end=time.monotonic()+120
 for redirect in range(6):
  url=valid_url(url)
  remaining=end-time.monotonic()
  if remaining<=0:raise Refusal()
  with open_url(url,min(30,remaining)) as response:
   if response.status in [301,302,303,307,308]:
    next_url=response.headers.get('Location')
    if redirect==5 or not next_url:raise Refusal()
    url=urllib.parse.urljoin(url,next_url);continue
   if response.status!=200:raise Refusal()
   length=response.headers.get('Content-Length')
   if length is not None and (not re.fullmatch('[0-9]+',length) or int(length)>MAX_BYTES):raise Refusal()
   size=0;digest=hashlib.sha256()
   with open(path,'xb') as candidate:
    while True:
     if time.monotonic()>=end:raise Refusal()
     chunk=response.read(65536)
     if not chunk:break
     size+=len(chunk)
     if size>MAX_BYTES:raise Refusal()
     digest.update(chunk);candidate.write(chunk)
    candidate.flush();os.fsync(candidate.fileno())
   if digest.hexdigest()!=pin:raise Refusal()
   return
 raise Refusal()
def install_candidate(candidate,pin):
 old_input=sys.stdin;output=io.StringIO()
 try:
  sys.stdin=io.StringIO(json.dumps({'candidate':str(candidate),'sha256':pin}))
  with contextlib.redirect_stdout(output):exec(INSTALL_PROGRAM,{'__name__':'__main__'})
 finally:sys.stdin=old_input
 result=json.loads(output.getvalue())
 if not isinstance(result,dict):raise Refusal()
 return result
def install(i):
 if not isinstance(i,dict) or set(i)!={'url','sha256'} or not isinstance(i['sha256'],str) or not re.fullmatch('[0-9a-f]{64}',i['sha256']):raise Refusal()
 if i['url'] is not None:valid_url(i['url'])
 if platform.system()!='Linux' or platform.machine()!='x86_64':raise Refusal('agent.platform_unsupported')
 # The existing pin is tested by the same custody and lock policy, without executing it.
 current=install_candidate('/usr/local/bin/orbit-agent',i['sha256'])
 if current=={'replaced':False,'sha256':i['sha256']}:return {'changed':False}
 if i['url'] is None:raise Refusal('agent.release_unavailable')
 with tempfile.TemporaryDirectory(prefix='orbit-observer-download-') as directory:
  candidate=pathlib.Path(directory)/'observer'
  download(i['url'],candidate,i['sha256'])
  result=install_candidate(candidate,i['sha256'])
  if set(result)!={'replaced','sha256'} or type(result['replaced']) is not bool or result['sha256']!=i['sha256']:raise Refusal()
  return {'changed':result['replaced']}
if __name__=='__main__':
 try:result=install(json.load(sys.stdin))
 except Refusal as e:result={'changed':False,'error_code':e.code}
 except Exception:result={'changed':False,'error_code':'node.bootstrap_install_failed'}
 print(json.dumps(result,separators=(',',':')))
`;

/** Observer delivery only: no agent action, configuration, credential or restart. */
export async function installObserver(
  url: string | null,
  sha256: string,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<z.infer<typeof Result>> {
  const input = {
    url: url === null ? null : Delivery.parse(url),
    sha256: Hash.parse(sha256),
  };
  signal?.throwIfAborted();
  const output = await invoke({
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/bin/python3", "-c", ObserverInstallProgram],
    stdin: JSON.stringify(input),
    timeoutMs: 300000,
  }, signal);
  signal?.throwIfAborted();
  if (!output.success) {
    throw new Error("Observer installation has no confirmed outcome");
  }
  try {
    return Result.parse(JSON.parse(output.stdout));
  } catch {
    throw new Error("Observer installation returned no bounded result");
  }
}
