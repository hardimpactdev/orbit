import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { removeNodeArtifacts, RemoveNodeProgram } from "./removal_system.ts";
Deno.test("direct removal uses fixed system source and bounded pre-effect refusals", async () => {
  const calls: unknown[] = [];
  const result = await removeNodeArtifacts({ node_id: 13 }, (argv, stdin) => {
    calls.push([
      argv[0],
      argv[1],
      argv[2] === RemoveNodeProgram,
      JSON.parse(stdin!),
    ]);
    return Promise.resolve({
      success: true,
      stdout: '{"error_code":"node.removal_node_mismatch"}',
    });
  });
  assertEquals(result, {
    ready: false,
    error_code: "node.removal_node_mismatch",
  });
  assertEquals(calls, [["/usr/bin/python3", "-c", true, { node_id: 13 }]]);
});
Deno.test("a malformed or failed direct system result stays uncertain", async () => {
  for (
    const reply of [{ success: false, stdout: "" }, {
      success: true,
      stdout: '{"removed":false}',
    }, { success: true, stdout: "garbage" }]
  ) {
    await assertRejects(
      () => removeNodeArtifacts({ node_id: 13 }, () => Promise.resolve(reply)),
      Error,
      "uncertain",
    );
  }
});
async function sandbox(
  mode:
    | "remove"
    | "foreign"
    | "busy"
    | "worker"
    | "sticky"
    | "unsafe-lock"
    | "retire"
    | "worker-active",
) {
  const definitions = RemoveNodeProgram.split("\ntry:\n result=remove_node")[0]
    .replaceAll("s.st_uid!=0", "s.st_uid!=os.geteuid()")
    .replaceAll("lockstat.st_uid!=0", "lockstat.st_uid!=os.geteuid()")
    .replace("deadline=time.monotonic()+300", "deadline=time.monotonic()");
  const script = definitions + String.raw`
import tempfile
with tempfile.TemporaryDirectory(prefix='orbit-remove-test-') as work:
 ROOT=pathlib.Path(work)
 for p in ['/run/lock','/etc/systemd/system','/usr/local/bin','/usr/local/libexec','/etc/orbit/agent','/keep']:
  path(p).mkdir(parents=True,exist_ok=True)
 if MODE=='sticky': path('/run/lock').chmod(0o1777)
 if MODE=='unsafe-lock': path('/run/lock').chmod(0o777)
 path('/etc/orbit/agent/config.toml').write_text('[swamp_worker]\nnode_id = '+str(14 if MODE=='foreign' else 13)+'\nuser = '+json.dumps(pwd.getpwuid(os.geteuid()).pw_name)+'\n')
 path('/etc/systemd/system/orbit-agent.service').write_text('observer')
 if MODE in ['worker','retire','worker-active']:
  path('/etc/systemd/system/orbit-node-worker.service').write_text('worker')
  path('/var/lib/orbit-node-worker').mkdir(parents=True,mode=0o700)
  path('/etc/orbit/swamp-worker').mkdir(mode=0o700)
  path('/etc/orbit/swamp-worker/server.token').write_text('private-token')
  path('/etc/orbit/swamp-worker/server.token').chmod(0o600)
  path('/usr/local/libexec/orbit-node-worker-check').write_text('checker')
 path('/usr/local/bin/orbit-agent').write_text('binary')
 path('/keep/sentinel').write_text('keep')
 calls=[]
 def systemctl(*args,required=True): calls.append(list(args))
 def service_inactive(unit):
  calls.append(['inactive',unit])
  if MODE=='worker-active' and unit=='orbit-node-worker.service': raise RuntimeError('worker active')
 lock=None
 if MODE=='busy':
  lock=open(path('/run/lock/orbit-self-update.lock'),'w')
  fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
 try: result=remove_node({'node_id':13,**({'retire_worker':True} if MODE in ['retire','worker-active'] else {})})
 except Exception: result={'error_code':'unsafe-local-state'}
 exists=path('/usr/local/bin/orbit-agent').exists()
 repeated=remove_node({'node_id':13}) if MODE=='remove' else None
 print(json.dumps({'result':result,'calls':calls,'agent_exists':exists,'neighbor':path('/keep/sentinel').read_text(),'repeated':repeated,'worker_exists':path('/etc/systemd/system/orbit-node-worker.service').exists(),'credentials_exist':path('/etc/orbit/swamp-worker').exists(),'cache_exists':path('/var/lib/orbit-node-worker').exists()}))
`;
  // Only test ownership is substituted; production has no alternate root or program input.
  const child = new Deno.Command("/usr/bin/python3", {
    args: ["-c", `MODE=${JSON.stringify(mode)}\n` + script],
    stdout: "piped",
    stderr: "piped",
  });
  const reply = await child.output();
  if (!reply.success) throw new Error(new TextDecoder().decode(reply.stderr));
  return JSON.parse(new TextDecoder().decode(reply.stdout));
}
Deno.test("ported removal program removes only owned observer files without stopping its worker", async () => {
  const outcome = await sandbox("remove");
  assertEquals(outcome.result, { removed: true });
  assertEquals(outcome.repeated, { removed: true });
  assertEquals(outcome.agent_exists, false);
  assertEquals(outcome.neighbor, "keep");
  assertEquals(outcome.calls, [
    ["stop", "orbit-agent.service"],
    ["disable", "orbit-agent.service"],
    ["inactive", "orbit-agent.service"],
    ["daemon-reload"],
    ["reset-failed", "orbit-agent.service"],
  ]);
});

Deno.test("off-worker retirement confirms worker inactivity before deleting its unit credentials cache and observer", async () => {
  const outcome = await sandbox("retire");
  assertEquals(outcome.result, { removed: true });
  assertEquals(outcome.calls.slice(0, 3), [
    ["disable", "orbit-node-worker.service"],
    ["stop", "orbit-node-worker.service"],
    ["inactive", "orbit-node-worker.service"],
  ]);
  assertEquals(outcome.worker_exists, false);
  assertEquals(outcome.credentials_exist, false);
  assertEquals(outcome.cache_exists, false);
  assertEquals(outcome.agent_exists, false);
  assertEquals(outcome.neighbor, "keep");
});

Deno.test("an unconfirmed worker stop preserves all files and prevents a successful retirement", async () => {
  const outcome = await sandbox("worker-active");
  assertEquals(outcome.result, { error_code: "unsafe-local-state" });
  assertEquals(outcome.worker_exists, true);
  assertEquals(outcome.credentials_exist, true);
  assertEquals(outcome.agent_exists, true);
});
Deno.test("foreign Node identity and held update lock preserve every agent file before system effects", async () => {
  for (const mode of ["foreign", "busy"] as const) {
    const outcome = await sandbox(mode);
    assertEquals(outcome.agent_exists, true);
    assertEquals(outcome.calls, []);
    assertEquals(
      outcome.result.error_code,
      mode === "foreign" ? "node.removal_node_mismatch" : "agent.update_busy",
    );
  }
});

Deno.test("live worker retirement refuses before changing any file or service", async () => {
  const outcome = await sandbox("worker");
  assertEquals(outcome.result, {
    error_code: "node.removal_worker_shutdown_unavailable",
  });
  assertEquals(outcome.calls, []);
  assertEquals(outcome.agent_exists, true);
  assertEquals(outcome.neighbor, "keep");
});

Deno.test("removal accepts the fixed root sticky lock directory and refuses a writable nonsticky directory", async () => {
  const sticky = await sandbox("sticky");
  assertEquals(sticky.result, { removed: true });
  assertEquals(sticky.agent_exists, false);
  const unsafe = await sandbox("unsafe-lock");
  assertEquals(unsafe.result, { error_code: "unsafe-local-state" });
  assertEquals(unsafe.calls, []);
  assertEquals(unsafe.agent_exists, true);
});
