import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { exists, nodeFixture, sshInput } from "../../../../tests/_support/node_host.ts";

async function fixture() {
  const f = await nodeFixture(true);
  for (const name of ["node-role-remove", "operation-node-remove", "operation-node-role-remove-direct"]) {
    await f.install(name);
  }
  const ssh = sshInput();
  const role = {
    schema_version: 1,
    node_id: 701,
    assignment_id: 101,
    role: "websocket",
    ssh,
    public_ssh: [],
    ci_host: [],
    commands: [],
    publications: [],
    remove_containers: [],
    certificates: [],
    stop_units: [{ ssh, input: { unit: "orbit-websocket.service" } }],
    firewall: [{
      ssh,
      input: { argv: ["ufw", "--force", "delete", "allow", "6001", "comment", "orbit:role:websocket"] },
    }],
    files: [{ ssh, path: `${f.host}/orbit-owned-unit` }],
    directories: [{ ssh, path: `${f.host}/orbit-owned-config` }],
    reload_units: [{ ssh }],
  };
  const intent = {
    schema_version: 1,
    node_id: 701,
    name: "disposable",
    offline: false,
    machine_deleted: false,
    platform: "linux",
    ssh,
    roles: [],
    public_ssh: { argv: ["ufw", "allow", "22"] },
    managed_keys: ssh.target.host_keys,
    hub: [],
    router_rules: [],
    peers: [],
    dns: [],
    footprints: { files: role.files, directories: role.directories },
    metrics_files: [],
    metrics_reload: [],
    retained_on_node: [],
    preserved_on_node: ["User data and host-side WireGuard"],
  };
  const file = `${f.host}/orbit-owned-unit`, config = `${f.host}/orbit-owned-config`, data = `${f.host}/user-data`;
  await Deno.mkdir(`${f.host}/home/.ssh`, { recursive: true });
  const keys = `${f.host}/home/.ssh/authorized_keys`, pin = role.ssh.target.host_keys[0];
  await Deno.writeTextFile(keys, pin + " control\nssh-ed25519 AAAAUnrelated operator\n");
  await Deno.writeTextFile(file, "owned");
  await Deno.mkdir(config);
  await Deno.writeTextFile(`${config}/secret`, "owned");
  await Deno.writeTextFile(data, "keep");
  const run = async (name = "node-role-remove", success = true, node = false) => {
    await f.repo.cli(["workflow", "validate", name]);
    return await f.repo.runWorkflow(name, {
      operation_id: 9001,
      node_id: 701,
      kind: node ? "node.remove" : "node.role_remove",
      intent: node ? intent : role,
      execution_secrets: {},
      ...(node ? {} : {offline: false}),
    }, { success });
  };
  const audit = () => Deno.readTextFileSync(`${f.host}/calls`);
  return { ...f, role, intent, file, config, data, keys, pin, run, audit };
}
for (const name of ["node-role-remove", "operation-node-role-remove-direct"]) {
  Deno.test(`${name} removes owned resources and preserves user data`, async () => {
    const f = await fixture();
    await using _ = f.repo;
    await f.run(name);
    assertFalse(exists(f.file));
    assertFalse(exists(f.config));
    assertEquals(await Deno.readTextFile(f.data), "keep");
    assert(f.audit().includes("stop orbit-websocket.service"));
    assert(f.audit().includes("daemon-reload"));
  });
}
Deno.test("role removal passes the admitted Caddy document to its child", async () => {
  const f = await fixture();
  await using _ = f.repo;
  f.role.publications.push(
    {
      node_id: 701,
      control_node_id: 209,
      host_keys: f.role.ssh.target.host_keys,
      connection: { nodeHost: "fixture", nodeUser: "fixture" },
      before: { caddy: { content: "before" }, php: null, dns: null },
      after: { caddy: { content: "# desired Caddy" }, php: null, dns: null },
    } as never,
  );
  await Deno.writeTextFile(
    `${f.repo.dir}/workflows/workflow-operation-route-caddy.yaml`,
    JSON.stringify({
      id: "13129719-09a9-4833-afb8-4eb1611fdd20",
      name: "operation-route-caddy",
      inputs: { type: "object" },
      jobs: [{
        name: "publish",
        steps: [{
          name: "Check the captured document",
          task: {
            type: "assert",
            severity: "high",
            expr: 'inputs.intent.caddy_publication.caddy.content == "# desired Caddy"',
            message: "Missing admitted Caddy document",
          },
        }],
      }],
    }),
  );
  await f.run();
  assertFalse(exists(f.file));
  assert(exists(f.data));
});
Deno.test("busy CI project refuses before removing runtime or paths", async () => {
  const f = await fixture();
  await using _ = f.repo;
  f.role.ci_host.push({ ssh: f.role.ssh, input: {} } as never);
  f.repo.env.CI_GUESTS = '[{"name":"runner"}]';
  await f.run("node-role-remove", false);
  assert(exists(f.file));
  assert(exists(f.config));
  assertFalse(exists(`${f.host}/calls`));
});
Deno.test("missing CI project is drained and the last role keeps public SSH", async () => {
  const f = await fixture();
  await using _ = f.repo;
  f.role.ci_host.push({ ssh: f.role.ssh, input: {} } as never);
  f.role.public_ssh.push({ ssh: f.role.ssh, input: { argv: ["ufw", "allow", "22"] } } as never);
  f.repo.env.CI_MISSING = "1";
  await f.run("operation-node-role-remove-direct");
  assertFalse(exists(f.file));
  assert(f.audit().includes("ufw allow 22"));
  assert(exists(f.data));
});
for (const flag of ["FAIL_STOP", "FIREWALL_ERROR"]) {
  Deno.test(`role removal fails on ${flag}`, async () => {
    const f = await fixture();
    await using _ = f.repo;
    f.repo.env[flag] = "1";
    await f.run("node-role-remove", false);
    if (flag === "FAIL_STOP") {
      assert(exists(f.file));
      assert(exists(f.config));
    }
  });
}
for (const offline of [false, true]) {
  Deno.test(`reachable Node removal with offline consent ${offline}`, async () => {
    const f = await fixture();
    await using _ = f.repo;
    f.intent.offline = offline;
    await f.run("operation-node-remove", true, true);
    assertFalse(exists(f.file));
    if (!offline) {
      assertFalse(exists(f.config));
      const keys = await Deno.readTextFile(f.keys);
      assertFalse(keys.includes(f.pin));
      assert(keys.includes("AAAAUnrelated"));
      assertEquals(await Deno.readTextFile(f.data), "keep");
    }
  });
}
Deno.test("unreachable Node requires consent and preserves its target", async () => {
  const f = await fixture();
  await using _ = f.repo;
  f.repo.env.OFFLINE = "1";
  await f.run("operation-node-remove", false, true);
  assert(exists(f.file));
  f.intent.offline = true;
  await f.run("operation-node-remove", true, true);
  assert(exists(f.file));
  assert((await Deno.readTextFile(f.keys)).includes(f.pin));
});
Deno.test("deleted machine skips its target without any SSH identity", async () => {
  const f = await fixture();
  await using _ = f.repo;
  Object.assign(f.intent, { machine_deleted: true, ssh: null, offline: true, roles: [] });
  await f.run("operation-node-remove", true, true);
  assert(exists(f.file));
  assertFalse(exists(`${f.host}/calls`));
});
