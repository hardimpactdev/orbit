import { assertEquals } from "jsr:@std/assert@1.0.19";
import { NETWORK_PROGRAM } from "./network_native_program.ts";

const driver = String.raw`
import io,shutil,types
root=tempfile.mkdtemp(prefix='orbit-network-test-')
WG=root+'/wireguard';os.mkdir(WG,0o700)
LOCK=root+'/peer.lock';ROOT_UID=os.getuid()
K='A'*43+'=';PRIVATE='B'*43+'='
calls=[];live={'active':True,'enabled':'enabled'};broken=None
resolvers={'orbit':{'dns':'10.44.0.1','domain':'~orbit','default-route':'no','llmnr':'no','mdns':'no','dnssec':'no','dnsovertls':'no','nta':''}}
def fake(name,args=(),data=None,check=True):
    global broken
    args=list(args);calls.append([name,args])
    if broken and broken(name,args):
        broken=None
        if check:raise Uncertain()
        return types.SimpleNamespace(returncode=1,stdout='')
    text='';code=0
    if name=='wg':
        if args==['genkey']:text=PRIVATE+'\n'
        elif args==['pubkey'] or args==['show','orbit','public-key']:text=K+'\n'
    elif name=='wg-quick':text='[Interface]\nPrivateKey = '+PRIVATE+'\n'
    elif name=='systemctl':
        action=args[0]
        if action=='is-active':code=0 if live['active'] else 3
        elif action=='is-enabled':text=live['enabled']+'\n';code=0 if live['enabled']=='enabled' else 1
        elif action=='disable':live['enabled']='disabled'
        elif action=='enable':live['enabled']='enabled'
        elif action=='mask':live['enabled']='masked'
        elif action=='unmask' and live['enabled']=='masked':live['enabled']='disabled'
        elif action in ['start','restart']:live['active']=True
        elif action=='stop':live['active']=False
    elif name=='resolvectl':
        if args==['dns']:text='Link 2 (eth0): 1.1.1.1\nLink 3 (orbit): 10.44.0.1\n'
        elif len(args)==2:text='Link 3 ('+args[1]+'): '+resolvers.setdefault(args[1],{}).get(args[0],'')
        else:resolvers.setdefault(args[1],{})[args[0]]=' '.join(args[2:])
    return types.SimpleNamespace(returncode=code,stdout=text)
command=fake
peer={'server_public_key':'C'*43+'=','peer_public_key':K,'endpoint':'vpn.example.test:51820','address':'10.44.0.13/24','subnet':'10.44.0.0/24','dns_server':'10.44.0.1','domains':['orbit','test'],'operator_dns':None,'retain':True}
write('orbit.key',PRIVATE+'\n')
old='[Interface]\nAddress = 10.44.0.13/24\nPrivateKey = '+PRIVATE+'\nPostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\~orbit\n\n[Peer]\nPublicKey = '+'C'*43+'=\n'
write('orbit.conf',old);write('orbit.dns-link','orbit\n10.44.0.1\norbit\n')
try:
    SCENARIO
finally:shutil.rmtree(root)
`;
async function python(scenario: string) {
  const program = NETWORK_PROGRAM.split("try:print(json.dumps(main()")[0];
  const child = await new Deno.Command("/usr/bin/python3", {
    args: [
      "-c",
      program +
      driver.replace(
        "    SCENARIO",
        scenario.split("\n").map((line) => "    " + line).join("\n"),
      ),
    ],
    stdout: "piped",
    stderr: "piped",
  }).output();
  assertEquals(child.success, true, new TextDecoder().decode(child.stderr));
}

Deno.test("native peer key preparation preserves an existing key inode and refuses a lost retained key", () =>
  python(`
before=os.stat(WG+'/orbit.key').st_ino
assert prepare({'existing':True})=={'public_key':K}
assert os.stat(WG+'/orbit.key').st_ino==before
remove('orbit.key')
try:prepare({'existing':True});assert False
except Refusal as error:assert str(error)=='wireguard.peer_key_missing'
assert read('orbit.key',True) is None
assert not any(name=='wg' and args==['genkey'] for name,args in calls)
`));

Deno.test("native peer key creation is private and retry reuses its inode", () =>
  python(`
remove('orbit.key');assert prepare({'existing':False})=={'public_key':K}
before=os.stat(WG+'/orbit.key')
assert stat.S_IMODE(before.st_mode)==0o600
assert prepare({'existing':False})=={'public_key':K}
assert os.stat(WG+'/orbit.key').st_ino==before.st_ino
assert sum(name=='wg' and args==['genkey'] for name,args in calls)==1
`));

Deno.test("native live hub publication synchronizes peers without restarting its active tunnel", () =>
  python(`
config='[Interface]\\nAddress = 10.44.0.1/24\\nPrivateKey = '+PRIVATE+'\\n'
assert hub({'config':config})=={}
assert read('orbit.conf')==config
assert ['wg',['syncconf','orbit',WG+'/orbit-native-runtime.conf']] in calls
assert not any(name=='systemctl' and args[0] in ['stop','restart'] for name,args in calls)
assert read('orbit-native-runtime.conf',True) is None
assert read('orbit-candidate.conf',True) is None
`));

Deno.test("native hub activation failure restores exact prior config and service state", () =>
  python(`
broken=lambda name,args:name=='wg' and args[0]=='syncconf'
try:hub({'config':'[Interface]\\nAddress = 10.44.0.1/24\\nPrivateKey = '+PRIVATE+'\\n'});assert False
except Refusal as error:assert str(error)=='wireguard.hub_start_failed'
assert read('orbit.conf')==old
assert live=={'active':True,'enabled':'enabled'}
assert read('orbit-native-runtime.conf',True) is None
`));

Deno.test("native peer failed activation restores original resolver config and service state", () =>
  python(`
broken=lambda name,args:name=='systemctl' and args[0]=='start'
try:publish(peer);assert False
except Refusal as error:assert str(error)=='wireguard.peer_config_failed'
assert read('orbit.conf')==old
assert read('orbit.dns-link')=='orbit\\n10.44.0.1\\norbit\\n'
assert read('.orbit.native-network-transaction',True) is None
assert live=={'active':True,'enabled':'enabled'}
`));

for (const configWritten of [false, true]) {
  Deno.test(`native resumed publication reapplies complete config and DNS after interruption (config written: ${configWritten})`, () =>
    python(`
owner='peer-'+hashlib.sha256(json.dumps(peer,sort_keys=True,separators=(',',':')).encode()).hexdigest()
transaction(owner)
${configWritten ? "write('orbit.conf',render_peer(PRIVATE+'\\n',peer))" : ""}
assert resolvers['orbit']['domain']=='~orbit'
assert publish(peer)=={'public_key':K}
assert read('orbit.conf')==render_peer(PRIVATE+'\\n',peer)
assert resolvers['orbit']['domain']=='~orbit ~test'
assert read('orbit.dns-link')=='orbit\\n10.44.0.1\\norbit\\ntest\\n'
assert read('.orbit.native-network-transaction',True) is not None
rollback({})
assert read('orbit.conf')==old
assert resolvers['orbit']['domain']=='~orbit'
`));
}

Deno.test("native DNS repair preserves unrelated config and never restarts the tunnel on success", () =>
  python(`
assert repair_dns({'dns_server':'10.44.0.1','domains':['orbit','test'],'public_key':K})=={}
assert '[Peer]\\nPublicKey = '+'C'*43+'=\\n' in read('orbit.conf')
assert '~test' in read('orbit.conf')
assert resolvers['orbit']['domain']=='~orbit ~test'
assert read('.orbit.native-network-transaction',True) is None
assert not any(name=='systemctl' and args[0] in ['start','stop','restart'] for name,args in calls)
`));

Deno.test("native DNS repair failure restores the prior bytes and resolver policy", () =>
  python(`
broken=lambda name,args:name=='resolvectl' and args==['domain','orbit','~orbit','~test']
try:repair_dns({'dns_server':'10.44.0.1','domains':['orbit','test'],'public_key':K});assert False
except Refusal as error:assert str(error)=='wireguard.peer_dns_apply_failed'
assert read('orbit.conf')==old
assert resolvers['orbit']['domain']=='~orbit'
assert read('.orbit.native-network-transaction',True) is None
`));

Deno.test("native network lock rejects a writable preexisting lock before key generation", () =>
  python(`
remove('orbit.key')
with open(LOCK,'w') as f:f.write('untrusted')
os.chmod(LOCK,0o666)
sys.stdin=io.StringIO(json.dumps({'name':'wireguard.prepare-peer-key','input':{'existing':False}}))
try:main();assert False
except Uncertain:pass
assert read('orbit.key',True) is None
assert not calls
`));

Deno.test("native failed-root recovery rolls back only its exact pending peer publication", () =>
  python(`
assert publish(peer)=={'public_key':K}
assert read('.orbit.native-network-transaction',True) is not None
public_peer=dict(peer);public_peer.pop('peer_public_key')
assert recover_peer({'peer':public_peer})=={'restore_public_key':True}
assert read('orbit.conf')==old
assert read('orbit.dns-link')=='orbit\\n10.44.0.1\\norbit\\n'
assert read('.orbit.native-network-transaction',True) is None
`));

Deno.test("native lost-commit recovery preserves a completed peer instead of undoing its live key", () =>
  python(`
publish(peer);commit({'public_key':K});calls.clear()
public_peer=dict(peer);public_peer.pop('peer_public_key')
assert recover_peer({'peer':public_peer})=={'restore_public_key':False}
assert read('orbit.conf')==render_peer(PRIVATE+'\\n',peer)
assert not any(name=='systemctl' and args[0] in ['start','stop','restart'] for name,args in calls)
`));

Deno.test("native failed-root recovery refuses a different pending peer transaction without effects", () =>
  python(`
publish(peer);before=read('orbit.conf');calls.clear()
public_peer=dict(peer,endpoint='different.example.test:51820');public_peer.pop('peer_public_key')
try:recover_peer({'peer':public_peer});assert False
except Refusal as error:assert str(error)=='wireguard.peer_recovery_pending'
assert read('orbit.conf')==before
assert read('.orbit.native-network-transaction',True) is not None
assert not any(name=='systemctl' and args[0] in ['start','stop','restart'] for name,args in calls)
`));
