import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  createTaskVmEnrollmentAdapter,
  TaskVmEnrollmentStages,
} from "./enrollment_direct.ts";
import {
  EnrollmentInput,
  type EnrollmentIntent,
  type EnrollmentNativeDependencies,
  nativeTaskVmEnrollmentActions,
} from "./enrollment.ts";
import type { SystemTransport } from "../node/system_command.ts";
const hash = "a".repeat(64);
const key = "b".repeat(43) + "=";
const publicBody =
  "AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB";
const sshIdentity = {
  ssh_host_key_type: "ssh-ed25519" as const,
  ssh_host_key: publicBody,
  ssh_host_fingerprint: "SHA256:RXm/ruZ0eTzRXKwi1AQEDynB0VgHQ2ac9KPSFdf/YnA",
};
function intent(): EnrollmentIntent {
  return {
    task_vm_id: 184,
    guest_node_id: 212,
    execution_node_id: 9,
    gateway_node_id: 14,
    issuer_node_id: 209,
    model_router_node_id: 3,
    _wireguard: {
      schema_version: 1,
      executor_node_id: 14,
      hub_id: 1,
      context_node_id: 212,
      excluded_node_id: null,
      agent_sha256: hash,
    },
    model_origin: "http://10.44.0.3:8317",
    provider: "incus",
    name: "tvm-184",
    user: "orbit",
    agent_sha256: hash,
    agent_artifact_url: "https://artifacts.example/observer",
    vm: { project: "orbit-tasks", name: "tvm-184" },
    agent: {
      configuration: 'gateway_url="https://gateway.orbit"\n',
      certificate: "public-ca",
      unit: "public-unit",
      checkouts: [],
    },
    bootstrap: {
      user: "orbit",
      public_key: "ssh-ed25519 AAAA",
      releases: ["resolute"],
      packages: ["wireguard-tools"],
      dns_policy: "managed",
    },
    node: {
      node_id: 212,
      name: "tvm-184",
      user: "orbit",
      wireguard_ip: "10.44.0.212",
    },
    network: {
      server_public_key: key,
      endpoint: "vpn.example:51820",
      address: "10.44.0.212/24",
      subnet: "10.44.0.0/24",
      dns_server: "10.44.0.1",
      domains: ["orbit"],
    },
    wireguard_public_key: null,
    pi_url: "http://10.44.0.212:3774",
    runtime: {
      guest_node_id: 212,
      user: "orbit",
      agent_sha256: hash,
      pi_sha256: "e".repeat(64),
      origin: "http://10.44.0.3:8317",
      models: ["fixture"],
      process: {
        process_id: 7,
        name: "orbit-process-7-pi-server.service",
        runtime: "systemd",
        agent_sha256: hash,
        content_hash: "f".repeat(64),
        desired: "running",
        on_demand: false,
        activation_pending: false,
        retired: null,
      },
    },
  };
}
function fixture() {
  const events: string[] = [];
  const privateValue: Record<string, unknown> = {
    secret_hash: null,
    pi_token: "1".repeat(64),
  };
  const material = {
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) => {
      await use(privateValue);
    },
    storeSecretOutput: (outputs: Record<string, unknown>) => {
      events.push("store:" + Object.keys(outputs).join(","));
      Object.assign(privateValue, outputs);
      return Promise.resolve();
    },
  };
  const guest: SystemTransport = (command) => {
    if (command.stdin === undefined) {
      events.push("ssh-public-observe");
      assertEquals(command.args.slice(0, 4), [
        "-n",
        "--",
        "/usr/bin/python3",
        "-c",
      ]);
      return Promise.resolve({
        success: true,
        stdout: "ssh-ed25519 " + publicBody + " guest-comment\n",
      });
    }
    events.push("bootstrap-system");
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(JSON.parse(command.stdin!).ssh_port, 22);
    assertEquals(JSON.parse(command.stdin!).firewall, false);
    assertEquals(
      command.args.some((part) => part.includes("orbit-agent command")),
      false,
    );
    return Promise.resolve({ success: true, stdout: '{"prepared":true}' });
  };
  const dependencies: EnrollmentNativeDependencies = {
    guest,
    system: (command) => {
      if (command.args.includes("is-active")) {
        events.push("services-active");
        return Promise.resolve({
          success: true,
          stdout: "active\nactive\n",
        });
      }
      events.push("pi-inspect");
      assertEquals(
        JSON.parse(command.stdin!).name,
        "pi.inspect-artifact",
      );
      return Promise.resolve({
        success: true,
        stdout: '{"current":true}',
      });
    },
    observer: (url, pin, system) => {
      events.push("observer-install");
      assertEquals([url, pin, system], [
        "https://artifacts.example/observer",
        hash,
        guest,
      ]);
      return Promise.resolve({ changed: false });
    },
    prepareNetwork: (_input, _guard, system) => {
      events.push("prepare-network");
      assertEquals(system, guest);
      return Promise.resolve({ wireguard_public_key: key });
    },
    publishNetwork: (_input, _network, selected, _guard, system) => {
      events.push("publish-network");
      assertEquals([selected, system], [key, guest]);
      return Promise.resolve({
        guest_node_id: 212,
        network_configured: true,
      });
    },
    identity: (args) => {
      events.push("exact-guest212");
      assertEquals(args, { node_id: 212, user: "orbit" });
      return Promise.resolve({
        ...args,
        hostname: "tvm-184",
        effective_user: "orbit",
        sudo_ready: true,
      });
    },
    runtime: () => {
      events.push("runtime-configure");
      return Promise.resolve({ process_id: 7, configured: true });
    },
    installProcess: () => Promise.resolve({ ready: true, state: "active" }),
    fetch: ((url, options) => {
      events.push("pi-capabilities");
      assertEquals(String(url), "http://10.44.0.212:3774/capabilities");
      assertEquals(
        (options!.headers as Record<string, string>).Authorization,
        "Bearer " + privateValue.pi_token,
      );
      return Promise.resolve(new Response(null, { status: 200 }));
    }) as typeof fetch,
  };
  return { events, material, privateValue, dependencies };
}
Deno.test("remaining enrollment lowers preserve bootstrap/network/runtime behavior without worker creation", async () => {
  const f = fixture();
  const input = intent();
  for (
    const stage of TaskVmEnrollmentStages.filter((stage) =>
      ![
        "issue-model",
        "publish-hub",
        "issue-worker",
        "configure-worker",
        "worker-ready",
      ].includes(stage)
    )
  ) {
    const actions = nativeTaskVmEnrollmentActions(
      input,
      async () => {},
      f.material,
      {},
      f.dependencies,
    );
    const result = await createTaskVmEnrollmentAdapter(
      input,
      async () => {},
      f.material,
      actions,
    ).execute(stage);
    if (stage === "bootstrap") {
      assertEquals(result, { bootstrapped: true, node: sshIdentity });
    }
    if (stage === "prepare-network") {
      input.wireguard_public_key = String(result.wireguard_public_key);
    }
    if (stage === "confirm-runtime") assertEquals(result.ready, true);
  }
  assertEquals(f.events, [
    "bootstrap-system",
    "observer-install",
    "ssh-public-observe",
    "prepare-network",
    "publish-network",
    "runtime-configure",
    "exact-guest212",
    "pi-inspect",
    "services-active",
    "pi-capabilities",
  ]);
});
Deno.test("production selects the shared native Process installer and preserves its typed refusal", async () => {
  const f = fixture();
  delete f.dependencies.installProcess;
  const input = intent();
  const content = "# Orbit Process 7\n[Service]\nExecStart=/usr/bin/true\n";
  input.runtime.process = {
    ...input.runtime.process,
    directory: "/home/orbit/releases/current",
  };
  if (input.runtime.process.runtime !== "systemd") {
    throw new Error("Expected systemd fixture");
  }
  input.runtime.process.content_hash = Array.from(
    new Uint8Array(
      await crypto.subtle.digest(
        "SHA-256",
        new TextEncoder().encode(content),
      ),
    ),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
  f.privateValue.installation = {
    runtime: "systemd",
    content,
    environment: "# Orbit Process 7\nTOKEN=private-value\n",
  };
  let commands = 0;
  f.dependencies.system = (command) => {
    commands++;
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args, [
      "-n",
      "--",
      "/usr/bin/test",
      "-d",
      "/home/orbit/releases/current",
    ]);
    assertEquals(command.stdin, undefined);
    return Promise.resolve({
      success: false,
      stdout: "private diagnostic must stay internal",
    });
  };
  f.dependencies.runtime = async (
    admitted,
    _guard,
    material,
    _artifact,
    transport,
    installer,
  ) => {
    const outcome = await installer(admitted.process, material, transport);
    assertEquals(outcome, { error_code: "process.release_unavailable" });
    if (!("error_code" in outcome)) throw new Error("Expected refusal");
    throw new Error(outcome.error_code);
  };
  const actions = nativeTaskVmEnrollmentActions(
    input,
    async () => {},
    f.material,
    {},
    f.dependencies,
  );
  await assertRejects(
    () =>
      actions["prepare-runtime"]!({
        input,
        guard: async () => {},
        privateMaterial: f.material,
      }),
    Error,
    "process.release_unavailable",
  );
  assertEquals(commands, 1);
  assertEquals(f.events, []);
});
Deno.test("actual runtime lower proves exact guest before artifact or private reads", async () => {
  const f = fixture();
  delete f.dependencies.runtime;
  f.dependencies.identity = () => Promise.reject(new Error("wrong-worker"));
  const actions = nativeTaskVmEnrollmentActions(
    intent(),
    async () => {},
    f.material,
    {},
    f.dependencies,
  );
  await assertRejects(
    () =>
      actions["prepare-runtime"]!({
        input: intent(),
        guard: async () => {},
        privateMaterial: f.material,
      }),
    Error,
    "wrong-worker",
  );
  assertEquals(f.events, []);
});
Deno.test("native enrollment binds guest, network and Pi selectors before executing any system command", () => {
  const input = intent();
  assertEquals(
    EnrollmentInput.safeParse({
      ...input,
      network: { ...input.network, address: "10.44.0.9/24" },
    }).success,
    false,
  );
  assertEquals(
    EnrollmentInput.safeParse({
      ...input,
      vm: { ...input.vm, name: "tvm-999" },
    }).success,
    false,
  );
  assertEquals(
    EnrollmentInput.safeParse({ ...input, pi_url: "http://10.44.0.9:3774" })
      .success,
    false,
  );
});

Deno.test("trusted UpCloud public SSH observation must match the admitted pinned key and exports no private transport", async () => {
  const f = fixture();
  const input = intent();
  input.provider = "upcloud";
  input.execution_node_id = input.gateway_node_id;
  input.vm = null;
  f.privateValue.first_visit = {
    kind: "ssh",
    address: "192.0.2.212",
    publicKey: "ssh-ed25519 " + publicBody + " admitted-comment",
    identity: "/private/managed-ssh",
  };
  const actions = nativeTaskVmEnrollmentActions(
    input,
    async () => {},
    f.material,
    {},
    f.dependencies,
  );
  const result = await actions.bootstrap!({
    input,
    guard: async () => {},
    privateMaterial: f.material,
  });
  assertEquals(result, { bootstrapped: true, node: sshIdentity });
  assertEquals(
    JSON.stringify(result).includes("/private/managed-ssh"),
    false,
  );
  const changed = Uint8Array.from(
    atob(publicBody),
    (character) => character.charCodeAt(0),
  );
  changed[50] = 2;
  f.privateValue.first_visit = {
    ...(f.privateValue.first_visit as object),
    publicKey: "ssh-ed25519 " + btoa(String.fromCharCode(...changed)),
  };
  await assertRejects(
    () =>
      actions.bootstrap!({
        input,
        guard: async () => {},
        privateMaterial: f.material,
      }),
    Error,
    "task_vm.host_key_failed",
  );
});
Deno.test("failed or malformed SSH observation stops bootstrap before worker readiness or runtime", async () => {
  for (const success of [false, true]) {
    const f = fixture();
    f.dependencies.observer = () => Promise.resolve({ changed: false });
    f.dependencies.guest = (command) =>
      Promise.resolve(
        command.stdin !== undefined
          ? { success: true, stdout: '{"prepared":true}' }
          : {
            success,
            stdout: "private untyped transport diagnostic",
          },
      );
    const input = intent();
    const actions = nativeTaskVmEnrollmentActions(
      input,
      async () => {},
      f.material,
      {},
      f.dependencies,
    );
    await assertRejects(
      () =>
        createTaskVmEnrollmentAdapter(
          input,
          async () => {},
          f.material,
          actions,
        ).execute("bootstrap"),
      Error,
      "task_vm.host_key_failed",
    );
    assertEquals(f.events, []);
    assertEquals(f.privateValue.secret_hash, null);
  }
});

Deno.test("plain Task VM legacy worker stages refuse before material reads or effects", async () => {
  const f = fixture();
  const actions = nativeTaskVmEnrollmentActions(
    intent(),
    async () => {},
    f.material,
    {},
    f.dependencies,
  );
  for (
    const stage of [
      "issue-worker",
      "configure-worker",
      "worker-ready",
    ] as const
  ) {
    assertEquals(actions[stage], undefined);
    await assertRejects(
      () =>
        createTaskVmEnrollmentAdapter(
          intent(),
          () =>
            Promise.reject(
              new Error("No Gateway guard should be read for a retired stage"),
            ),
          f.material,
          actions,
        ).execute(stage),
      Error,
      "operation.kind_unavailable",
    );
  }
  assertEquals(f.events, []);
});
