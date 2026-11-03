/** The actual 209 parent DAG and official SSH at disposable provider and runtime boundaries. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
import { assertSwampLabels, Peer, provisionComposition, resultOf, stub } from "../../../../tests/_support/task_vm_provision.ts";

const ForeignRuntime = { task_vm_id: 43, guest_node_id: 142, process_id: 33, ready: true };
const hasRun = (argv: string[], run: string[]) =>
  argv.some((_, index) => run.every((word, offset) => argv[index + offset] === word));

Deno.test("the same root returns a complete public receipt and keeps 209's SSH order", async () => {
  await using test = await provisionComposition();
  await test.repo.cli(["workflow", "validate", "operation-task-vm-provision"]);
  const { history } = await test.root();
  assertEquals(history.inputs.node_id, 9);
  const result = resultOf(history);
  assertEquals(result.task_vm_id, 42);
  assert(result.ready);
  assertEquals(result.enrollment.node.wireguard_public_key, Peer);
  assertEquals(result.enrollment.node.observer_digest, "e".repeat(64));
  const calls = await test.eventsText();
  for (const absent of ["worker connect", "GatewayOperation", "python3"]) assert(!calls.includes(absent), absent);
  for (const present of ["10.44.0.142", "openssh-server", "10.44.0.15"]) assert(calls.includes(present), present);
  const guest = test.guest().map((event) => event.argv);
  // Bootstrap is argv only: the account proves sudo, the agent binary its pinned digest.
  const tail = (length: number) => guest.map((argv) => JSON.stringify(argv.slice(-length)));
  assert(tail(8).includes(JSON.stringify(["/usr/bin/sudo", "-n", "-u", "orbit", "--", "/usr/bin/sudo", "-n", "true"])));
  assert(tail(4).includes(JSON.stringify(["/usr/bin/sha256sum", "--check", "--strict", "-"])));
  const wireguard = calls.indexOf("wg-quick@orbit.service");
  assert(wireguard >= 0 && calls.indexOf("orbit-agent.service", wireguard) > wireguard);
  // The hub gets only this guest's peer file, then loads it live; no other peer is rewritten.
  const hub = Object.fromEntries(
    test.records().filter((record) => record.behavior === "@swamp/cfgmgmt/file").map((record) => [record.pathname, record.content]),
  );
  assertEquals(hub, {
    "/etc/wireguard/orbit-peers.d/142.conf": `[Peer]\n# tvm-42\nPublicKey = ${Peer}\nAllowedIPs = 10.44.0.142/32\n`,
  });
  assert(guest.some((argv) => hasRun(argv, ["wg", "addconf", "orbit", "/etc/wireguard/orbit-peers.d/142.conf"])));
  assert(!test.intent.peer_files.config.includes("PrivateKey ="));
  // One firewall command: only 209 reaches TCP 22, its allow ahead of the deny; no script reaches the guest.
  const firewall = guest.map((argv) => argv.at(-1)!).find((last) => last.startsWith("ufw --force delete"))!;
  assert(
    firewall.indexOf("prepend deny in proto tcp to any port 22") <
      firewall.indexOf("prepend allow in on orbit proto tcp from 10.44.0.15"),
  );
  assert(firewall.endsWith("ufw --force enable"));
  const written = Object.fromEntries(
    guest.filter((argv) => argv.length > 3 && argv.at(-3)!.includes("printf %s"))
      .map((argv) => [argv.at(-3)!.split("> ").at(-1), argv.at(-1)]),
  );
  assertEquals(written["/etc/wireguard/orbit.conf"], test.intent.peer_files.config);
  assertEquals(written["/etc/wireguard/orbit.dns-link"], test.intent.peer_files.dns_marker);
});

Deno.test("a foreign bootstrap account fails before network or runtime", async () => {
  await using test = await provisionComposition();
  await Deno.writeTextFile(test.verifyFault, "");
  const { run } = await test.root(false);
  assertEquals(run.status, "failed");
  const guest = test.guest().map((event) => event.argv);
  assert(guest.some((argv) => hasRun(argv, ["/usr/bin/sudo", "-n", "true"])));
  assert(!(await test.eventsText()).includes("wg pubkey"));
});

Deno.test("unavailable UpCloud refuses before provider or guest actions", async () => {
  await using test = await provisionComposition();
  test.intent.provider = "upcloud";
  const { run } = await test.root(false);
  assertEquals(run.status, "failed");
  assert(!await test.eventsExist());
});

Deno.test("a foreign runtime receipt never becomes ready", async () => {
  await using test = await provisionComposition();
  await stub(test.repo, "task-vm-runtime", "result", ForeignRuntime, true);
  const { run } = await test.root(false);
  assertEquals(run.status, "failed");
  // Rule 22: no rollback chain. The published peer stays for diagnosis; the next hub convergence drops it.
  assert(!(await test.eventsText()).includes("restor"));
});

Deno.test("native cancellation has no ready receipt and no rollback", async () => {
  await using test = await provisionComposition();
  await test.repo.stubWorkflow("task-vm-runtime", "sleep 30");
  const { run } = await test.root(false, "--timeout", "15s");
  assertEquals(run.status, "cancelled");
  assert(!(await test.eventsText()).includes("restor"));
});

Deno.test("tasks.provision's default queue budget covers a loaded dispatch", () => {
  const workflow = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-operation-task-provision.yaml`)) as {
    queueTimeout: number;
  };
  assertEquals(workflow.queueTimeout, 30);
  for (
    const name of [
      "task-vm-provision",
      "operation-task-vm-provision",
      "task-vm-ssh-prepare",
      "task-vm-incus-first-visit",
      "task-vm-enrollment",
    ]
  ) assertSwampLabels(name);
});
