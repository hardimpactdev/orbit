const FootprintProgram = await Deno.readTextFile(
    new URL("../fleet-recipe/scripts/footprint.py", import.meta.url),
);
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { footprintSystem } from "./footprint_system.ts";

const setup = String.raw`
import json, os, tempfile, hashlib
from pathlib import Path
ns={'__name__':'sandbox'}
exec(SOURCE,ns)
base=Path(tempfile.mkdtemp())
ns.update(UID=os.getuid(),GID=os.getgid(),ROOT=base/'releases',SERVICE=base/'units'/'orbit-private-dns.service',SOCKET=base/'units'/'orbit-private-dns.socket',TMPFILES=base/'orbit.conf')
original_directory=ns['directory']
ns['directory']=lambda path: None if path==base else original_directory(path)
calls=[]
ns['command']=lambda *args: calls.append(args) is None
files=[{'path':'app/a.php','contents':'<?php echo 1;'}, {'path':'serve.php','contents':'<?php echo 2;'}]
for f in files: f['sha256']=hashlib.sha256(f['contents'].encode()).hexdigest()
rid=hashlib.sha256(''.join(f['path']+'\0'+f['sha256']+'\n' for f in files).encode()).hexdigest()[:16]
service,socket=ns['units']('/var/lib/orbit/private-dns/releases/'+rid,'10.44.0.1')
payload=dict(release_id=rid,files=files,service=service,socket=socket)
`;
async function python(body: string) {
    const command = new Deno.Command("/usr/bin/python3", {
        args: [
            "-c",
            "SOURCE=" + JSON.stringify(FootprintProgram) + "\n" + setup +
            "\ntry:\n" +
            body.split("\n").map((line) => "    " + line).join("\n") +
            "\nfinally:\n    import shutil; shutil.rmtree(base)\n",
        ],
        stdout: "piped",
        stderr: "piped",
    });
    const result = await command.output();
    if (!result.success) {
        throw new Error(new TextDecoder().decode(result.stderr));
    }
}
Deno.test("tmpfiles publishes atomic owned 0644 file then preserves inode", async () => {
    await python(String.raw`
r={'rule':'# Orbit\ne /tmp/orbit-* - - - 1d\ne /dev/shm/orbit-* - - - 1d\n'}
assert ns['tmpfiles'](r)=={'changed':True}
p=ns['TMPFILES']; inode=p.stat().st_ino
assert p.read_text()==r['rule'] and p.stat().st_mode&0o7777==0o644
assert ns['tmpfiles'](r)=={'changed':False} and p.stat().st_ino==inode
p.chmod(0o600)
assert ns['tmpfiles'](r)=={'changed':True} and p.stat().st_mode&0o7777==0o644
`);
});
Deno.test("tmpfiles refuses foreign rules before writes and replaces symlink itself", async () => {
    await python(String.raw`
for rule in ['', 'r /etc/passwd\n', 'e /tmp/foreign - - - 1d\n','e /tmp/orbit-\0']:
    try: ns['tmpfiles']({'rule':rule}); raise AssertionError('accepted')
    except ns['Invalid']: pass
assert not ns['TMPFILES'].exists()
foreign=base/'foreign';foreign.write_text('preserved')
ns['TMPFILES'].symlink_to(foreign)
ns['tmpfiles']({'rule':'e /tmp/orbit-* - - - 1d\n'})
assert foreign.read_text()=='preserved' and not ns['TMPFILES'].is_symlink()
`);
});
Deno.test("listener validates content and fixed units before install, retains existing sockets", async () => {
    await python(String.raw`
assert ns['listener'](payload)=={'installed':True,'changed':True}
assert (ns['ROOT']/rid/'serve.php').read_text()==files[1]['contents']
assert ns['SERVICE'].read_text()==service
assert ('systemctl','restart','orbit-private-dns.service') in calls
assert not any('start' in c or 'enable' in c or 'stop' in c for c in calls)
calls.clear()
inode=ns['SERVICE'].stat().st_ino
assert ns['listener'](payload)=={'installed':True,'changed':False}
assert ns['SERVICE'].stat().st_ino==inode
assert not any(c[0]=='systemctl' for c in calls)
`);
});
Deno.test("listener refuses tampering, traversal, duplicate paths and foreign units", async () => {
    await python(String.raw`
import copy
for mutate in [lambda p:p.update(service=p['service']+'ExecStartPre=bad\n'),lambda p:p['files'][0].update(contents='tampered'),lambda p:p['files'][0].update(path='app/../bad.php'),lambda p:p['files'].append(p['files'][-1])]:
    p=copy.deepcopy(payload);mutate(p)
    try: ns['listener'](p); raise AssertionError('accepted')
    except ns['Invalid']: pass
assert calls==[] and not ns['ROOT'].exists()
ns['directory'](ns['SERVICE'].parent)
ns['SERVICE'].write_text('foreign unit')
try: ns['listener'](payload); raise AssertionError('accepted')
except ns['Invalid']: pass
assert ns['SERVICE'].read_text()=='foreign unit' and calls==[]
`);
});
Deno.test("listener self-test failure never publishes release or units", async () => {
    await python(String.raw`
ns['command']=lambda *args: False
try: ns['listener'](payload); raise AssertionError('accepted')
except RuntimeError: pass
assert not ns['SERVICE'].exists() and not ns['SOCKET'].exists()
assert list(ns['ROOT'].iterdir())==[]
`);
});
Deno.test("listener activation failure restores prior units, bytes, modes and release", async () => {
    await python(String.raw`
ns['listener'](payload)
release=ns['ROOT']/rid
(release/'serve.php').write_text('prior-corrupt-release')
ns['SERVICE'].chmod(0o600)
old=ns['SERVICE'].read_bytes()
calls.clear()
restarts=0
def command(*args):
    global restarts
    calls.append(args)
    if args==('systemctl','restart','orbit-private-dns.service'):
        restarts+=1
        return restarts!=1
    return True
ns['command']=command
try: ns['listener'](payload); raise AssertionError('accepted')
except RuntimeError: pass
assert ns['SERVICE'].read_bytes()==old and ns['SERVICE'].stat().st_mode&0o7777==0o600
assert (release/'serve.php').read_text()=='prior-corrupt-release'
assert restarts==2
`);
});
Deno.test("fixed command seam sends public input on stdin and strips arbitrary output", async () => {
    const call = footprintSystem((command) => {
        assertEquals(command.command, "/usr/bin/sudo");
        assertEquals(command.args.slice(0, 3), [
            "-n",
            "/usr/bin/python3",
            "-c",
        ]);
        assertEquals(command.stdin, '{"rule":"data"}');
        return Promise.resolve({
            success: true,
            stdout: '{"changed":true,"private":"discard"}',
        });
    }, FootprintProgram);
    assertEquals(await call("node.publish-tmpfiles", { rule: "data" }), {
        changed: true,
    });
    await assertRejects(
        () =>
            footprintSystem(
                () => Promise.resolve({ success: false, stdout: "private" }),
                FootprintProgram,
            )(
                "node.publish-tmpfiles",
                {},
            ),
        Error,
        "no confirmed outcome",
    );
    await assertRejects(
        () =>
            footprintSystem(() =>
                Promise.resolve({
                    success: true,
                    stdout: '{"changed":false,"error_code":"private"}',
                }), FootprintProgram)("node.publish-tmpfiles", {}),
        Error,
        "invalid outcome",
    );
});
Deno.test("listener installation leaves inactive services and catalog activation to publisher", async () => {
    await python(String.raw`
def inactive(*args):
    calls.append(args)
    return args[:3]!=('systemctl','is-active','--quiet')
ns['command']=inactive
ns['listener'](payload)
assert ('systemctl','daemon-reload') in calls
assert not any(c[0]=='systemctl' and c[1] in ('start','restart','enable','stop') for c in calls)
assert not (base/'catalog.json').exists()
`);
});
Deno.test("listener rejects release-directory symlink before any subprocess", async () => {
    await python(String.raw`
ns['directory'](ns['ROOT'])
foreign=base/'foreign';foreign.mkdir();(foreign/'preserve').write_text('same')
(ns['ROOT']/rid).symlink_to(foreign,target_is_directory=True)
try: ns['listener'](payload); raise AssertionError('accepted')
except ns['Invalid']: pass
assert calls==[] and (foreign/'preserve').read_text()=='same'
`);
});
