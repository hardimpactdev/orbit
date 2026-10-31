import { assertEquals } from "jsr:@std/assert@1.0.19";
import {
  BootstrapHostProgram,
  BootstrapInstallProgram,
} from "./bootstrap_program.ts";

async function python(program: string, test: string) {
  const source =
    "import json,sys\nns={'__name__':'bootstrap_test'}\nexec(json.load(sys.stdin),ns)\n" +
    test;
  const child = new Deno.Command("/usr/bin/python3", {
    args: ["-c", source],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify(program)));
  await writer.close();
  const output = await child.output();
  assertEquals(output.success, true, new TextDecoder().decode(output.stderr));
}
Deno.test("native bootstrap program parses package authorities and refuses non-source URI transports", async () => {
  await python(
    BootstrapHostProgram,
    String.raw`
hosts=ns['sourcehosts']("'https://archive.ubuntu.com/ubuntu/a' 4 x\n'https://archive.ubuntu.com/ubuntu/b' 4 x\n'file:/cache' 3 x\n'https://[2001:db8::1]/repo' 3 x\n")
assert hosts==['archive.ubuntu.com','2001:db8::1']
for value in ["'ssh://attacker/repo' 0 x","'https:///missing-host' 0 x"]:
 try:ns['sourcehosts'](value);raise AssertionError('unsafe source accepted')
 except ns['Refusal'] as e:assert e.code=='node.bootstrap_dns_refused'
`,
  );
});
Deno.test("native bootstrap refuses a symlink account path before mutation", async () => {
  await python(
    BootstrapHostProgram,
    String.raw`
import pathlib,tempfile
with tempfile.TemporaryDirectory() as d:
 target=pathlib.Path(d)/'target';target.mkdir()
 link=pathlib.Path(d)/'link';link.symlink_to(target)
 try:ns['real'](link,True);raise AssertionError('symlink accepted')
 except ns['Refusal'] as e:assert e.code=='node.bootstrap_unsafe'
 ns['real'](target,True)
`,
  );
});
Deno.test("native bootstrap restores exact retained DNS after an interrupted package effect", async () => {
  await python(
    BootstrapHostProgram,
    String.raw`
import os,tempfile
calls=[];updates=0;probes=0
with tempfile.TemporaryDirectory() as d:
 original=os.open
 def opened(path,flags,mode=0o777,*args,**kw):
  return original(d+'/peer.lock' if str(path)=='/run/lock/orbit-wireguard-peer.lock' else path,flags,mode,*args,**kw)
 def run(argv,timeout=30,required=True):
  global updates,probes
  calls.append(argv)
  if argv[0]=='/usr/bin/apt-get' and '--print-uris' in argv:return 0,"'https://archive.ubuntu.com/repo' 0 x"
  if argv[0]=='/usr/bin/apt-get' and argv[-1]=='update':
   updates+=1;return (1 if updates==1 else 0),''
  if argv[0]=='/usr/bin/getent':
   probes+=1;return (1 if probes==1 else 0),''
  if 'install' in argv:raise ns['Refusal']('node.bootstrap_failed')
  return 0,''
 ns['run']=run;ns['owned_dns']=lambda:'10.44.0.1';ns['os'].open=opened
 try:
  try:ns['packages']({'dns_policy':'managed','packages':['curl']});raise AssertionError('install failure settled')
  except ns['Refusal'] as e:assert e.code=='node.bootstrap_failed'
 finally:ns['os'].open=original
assert calls[-2:]==[['/usr/bin/resolvectl','dns','orbit','10.44.0.1'],['/usr/bin/resolvectl','domain','orbit','~.']]
`,
  );
});
Deno.test("observer installer is syntactically valid without executing a candidate binary", async () => {
  const child = new Deno.Command("/usr/bin/python3", {
    args: [
      "-c",
      "import sys;compile(sys.stdin.read(),'bootstrap-observer','exec')",
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(BootstrapInstallProgram));
  await writer.close();
  assertEquals((await child.output()).success, true);
  assertEquals(BootstrapInstallProgram.includes("subprocess"), false);
});
