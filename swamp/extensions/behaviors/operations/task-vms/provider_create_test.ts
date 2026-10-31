import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  createTaskVmProvider,
  type ProviderCreateIntent,
} from "./provider_create.ts";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";
import type { CloudRequest } from "./upcloud_sweep.ts";
const pin = "a".repeat(64);
const image = "b".repeat(64);
const id = "11111111-1111-4111-8111-111111111111";
const source = "22222222-2222-4222-8222-222222222222";
const key = "ssh-ed25519 YWJj pinned";
const secret =
  "-----BEGIN OPENSSH PRIVATE KEY-----\ncHJpdmF0ZS1ieXRlcw==\n-----END OPENSSH PRIVATE KEY-----\n";
const now = 1900000000000;
const privateData: {
  upcloud_token: string;
  provider_bootstrap: { private: string; public: string } | null;
} = {
  upcloud_token: "ucat_private_token",
  provider_bootstrap: { private: secret, public: key },
};
const stores: unknown[] = [];
const material = {
  read: () =>
    Promise.reject(new Error("This lower never reads parent Operations")),
  withPrivateMaterial: async (
    use: (value: Record<string, unknown>) => Promise<void>,
  ) => await use(privateData),
  storeSecretOutput: (value: Record<string, unknown>) => {
    stores.push(value);
    privateData.provider_bootstrap = value.provider_bootstrap as {
      private: string;
      public: string;
    };
    return Promise.resolve();
  },
};
const incus: ProviderCreateIntent = {
  task_vm_id: 184,
  node_id: 9,
  name: "tvm-184",
  provider: "incus",
  agent_sha256: pin,
  deadline_at: new Date(now + 900000).toISOString(),
  vm: { project: "task-vms", name: "tvm-184", cidr: "10.35.0.0/24" },
  create: {
    kind: "create",
    image,
    expected_fingerprint: image,
    base_alias: "orbit-task-base",
    gateway_public_key: key,
    cpus: 4,
    memory: "4GiB",
    disk: "40GiB",
    network: "task-vms",
    pool: "default",
    topology: true,
  },
  upcloud: null,
  provider_id: null,
};
const upcloud: ProviderCreateIntent = {
  ...incus,
  node_id: 14,
  provider: "upcloud",
  vm: null,
  create: null,
  upcloud: {
    zone: "fi-hel1",
    plan: "DEV-2xCPU-4GB",
    disk_gb: 40,
    max_vms: 10,
    source_template: source,
    gateway_public_key: key,
  },
};
const ready = {
  running: true,
  settled: true,
  address: "10.35.0.4",
  image_fingerprint: image,
};
Deno.test("provider create composes direct Incus with the admitted image and waits for actual address", async () => {
  const calls: SystemCommand[] = [];
  let reads = 0;
  const invoke: SystemTransport = (command) => {
    calls.push(command);
    reads++;
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify([{
        name: incus.name,
        type: "virtual-machine",
        status: "Running",
        config: { "volatile.base_image": image },
        state: {
          network: {
            eth0: {
              addresses: reads < 3
                ? []
                : [{ family: "inet", address: ready.address }],
            },
          },
        },
      }]),
    });
  };
  const result = await createTaskVmProvider(
    incus,
    async () => {},
    material,
    invoke,
    undefined,
    { now: () => now, wait: () => Promise.resolve() },
  );
  assertEquals(result, {
    provider_id: null,
    address: ready.address,
    ssh_host_key: null,
    image_fingerprint: image,
    running: true,
    settled: true,
  });
  assertEquals(
    calls.every((c) =>
      c.command === "/usr/bin/sudo" && c.args.includes("/usr/bin/incus")
    ),
    true,
  );
  assertEquals(
    calls.some((c) => c.args.some((arg) => arg.includes("orbit-agent"))),
    false,
  );
});
Deno.test("provider create refuses a different Incus image without effects", async () => {
  const calls: SystemCommand[] = [];
  const invoke: SystemTransport = (command) => {
    calls.push(command);
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify([{
        name: incus.name,
        type: "virtual-machine",
        status: "Running",
        config: { "volatile.base_image": pin },
      }]),
    });
  };
  await assertRejects(() =>
    createTaskVmProvider(incus, async () => {}, material, invoke, undefined, {
      now: () => now,
    })
  );
  assertEquals(calls.length, 1);
});
function fixture() {
  let exists = false;
  let loseCreate = false;
  let tier: string | null = null;
  let budget = 0;
  let foreign = false;
  const calls: { method: string; path: string; body: unknown }[] = [];
  const server = () => ({
    uuid: id,
    title: upcloud.name,
    hostname: upcloud.name,
    state: "started",
    labels: {
      label: [{ key: "orbit-task-vm", value: foreign ? "999" : "184" }],
    },
    ip_addresses: {
      ip_address: [{ access: "public", family: "IPv4", address: "95.216.1.2" }],
    },
  });
  const request: CloudRequest = (method, path, body) => {
    calls.push({ method, path, body });
    if (method === "GET" && path === "server?label=orbit-task-vm") {
      return Promise.resolve({
        servers: {
          server: exists
            ? [{ uuid: id, title: upcloud.name }]
            : Array.from({ length: budget }, (_, i) => ({
              uuid: id,
              title: `tvm-${i + 1}`,
            })),
        },
      });
    }
    if (method === "GET" && path === `server/${id}`) {
      return Promise.resolve(exists ? { server: server() } : null);
    }
    if (path === "plan") {
      return Promise.resolve({
        plans: { plan: [{ name: upcloud.upcloud!.plan, storage_tier: tier }] },
      });
    }
    if (method === "POST" && path === "server") {
      exists = true;
      return loseCreate
        ? Promise.reject(new Error(secret))
        : Promise.resolve({ server: { uuid: id } });
    }
    return Promise.reject(new Error("Unexpected fixture request"));
  };
  return {
    calls,
    request,
    set exists(value: boolean) {
      exists = value;
    },
    set loseCreate(value: boolean) {
      loseCreate = value;
    },
    set tier(value: string | null) {
      tier = value;
    },
    set budget(value: number) {
      budget = value;
    },
    set foreign(value: boolean) {
      foreign = value;
    },
  };
}
const noAgent = () =>
  Promise.reject(new Error("UpCloud API must not use agent"));
Deno.test("UpCloud uses exact ownership and private cloud-init; null plan tier is omitted", async () => {
  const f = fixture();
  const result = await createTaskVmProvider(
    upcloud,
    async () => {},
    material,
    noAgent,
    undefined,
    { now: () => now, request: f.request },
  );
  const body = f.calls.find((c) => c.method === "POST")!.body as {
    server: Record<string, unknown>;
  };
  const cloud = JSON.parse(String(body.server.user_data).slice(14));
  assertEquals(cloud.ssh_keys, {
    ed25519_private: secret,
    ed25519_public: key,
  });
  assertEquals(cloud.users, [{
    name: "orbit",
    lock_passwd: true,
    shell: "/bin/bash",
    sudo: "ALL=(ALL) NOPASSWD:ALL",
    ssh_authorized_keys: [key],
  }]);
  assertEquals(cloud.disable_root, true);
  assertEquals(cloud.ssh_deletekeys, true);
  assertEquals(cloud.ssh_genkeytypes, []);
  assertEquals(body.server.labels, {
    label: [{ key: "orbit-task-vm", value: "184" }],
  });
  assertEquals(body.server.storage_devices, {
    storage_device: [{
      action: "clone",
      storage: source,
      title: "tvm-184-disk",
      size: 40,
    }],
  });
  assertEquals(result, {
    provider_id: id,
    address: "95.216.1.2",
    ssh_host_key: key,
    image_fingerprint: source,
    running: true,
    settled: true,
  });
  assertEquals(JSON.stringify(result).includes(secret), false);
  assertEquals(JSON.stringify(result).includes("ucat_"), false);
});
Deno.test("UpCloud resumes discovery without duplicate create after a lost reply", async () => {
  const f = fixture();
  f.loseCreate = true;
  await createTaskVmProvider(
    upcloud,
    async () => {},
    material,
    noAgent,
    undefined,
    { now: () => now, request: f.request },
  );
  assertEquals(f.calls.filter((c) => c.method === "POST").length, 1);
});
Deno.test("existing owned UpCloud instance needs no new plan or server mutation", async () => {
  const f = fixture();
  f.exists = true;
  await createTaskVmProvider(
    upcloud,
    async () => {},
    material,
    noAgent,
    undefined,
    { now: () => now, request: f.request },
  );
  assertEquals(f.calls.every((c) => c.method === "GET"), true);
  assertEquals(f.calls.some((c) => c.path === "plan"), false);
});
Deno.test("foreign same-name UpCloud instance refuses before mutation", async () => {
  const f = fixture();
  f.exists = true;
  f.foreign = true;
  await assertRejects(() =>
    createTaskVmProvider(
      upcloud,
      async () => {},
      material,
      noAgent,
      undefined,
      { now: () => now, request: f.request },
    )
  );
  assertEquals(f.calls.every((c) => c.method === "GET"), true);
});
Deno.test("UpCloud budget blocks allocation without provider mutation", async () => {
  const f = fixture();
  f.budget = 10;
  await assertRejects(() =>
    createTaskVmProvider(
      upcloud,
      async () => {},
      material,
      noAgent,
      undefined,
      { now: () => now, request: f.request },
    )
  );
  assertEquals(f.calls.every((c) => c.method === "GET"), true);
});
Deno.test("known missing UpCloud UUID does not cause blind replacement", async () => {
  const f = fixture();
  await assertRejects(() =>
    createTaskVmProvider(
      { ...upcloud, provider_id: id },
      async () => {},
      material,
      noAgent,
      undefined,
      { now: () => now, request: f.request },
    )
  );
  assertEquals(f.calls.every((c) => c.method === "GET"), true);
});
Deno.test("expired or superseded parent has no provider effect", async () => {
  const f = fixture();
  await assertRejects(() =>
    createTaskVmProvider(
      upcloud,
      async () => {},
      material,
      noAgent,
      undefined,
      { now: () => now + 900001, request: f.request },
    )
  );
  await assertRejects(
    () =>
      createTaskVmProvider(
        upcloud,
        () => Promise.reject(new Error(secret)),
        material,
        noAgent,
        undefined,
        { now: () => now, request: f.request },
      ),
    Error,
    "task_vm.provider_create_refused",
  );
  assertEquals(f.calls, []);
});
Deno.test("private callback failures are redacted and never become output", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      createTaskVmProvider(
        upcloud,
        async () => {},
        {
          ...material,
          withPrivateMaterial: () => Promise.reject(new Error(secret)),
        },
        noAgent,
        undefined,
        { now: () => now, request: f.request },
      ),
    Error,
    "task_vm.provider_create_refused",
  );
  assertEquals(f.calls, []);
});
Deno.test("UpCloud stages and rereads one host key before create; lost provider reply retains that pin", async () => {
  const f = fixture();
  f.loseCreate = true;
  let pair: { private: string; public: string } | null = null, generations = 0;
  const events: string[] = [];
  const source = {
    ...material,
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) => {
      events.push("read");
      await use({ ...privateData, provider_bootstrap: pair });
    },
    storeSecretOutput: (value: Record<string, unknown>) => {
      events.push("store");
      pair = value.provider_bootstrap as { private: string; public: string };
      return Promise.resolve();
    },
  };
  const request: CloudRequest = async (...args) => {
    if (args[0] === "POST") {
      events.push("create");
      assertEquals(pair?.public, key);
    }
    return await f.request(...args);
  };
  const options = {
    now: () => now,
    request,
    hostKey: () => {
      generations++;
      return Promise.resolve({ private: secret, public: key });
    },
  };
  const first = await createTaskVmProvider(
    upcloud,
    async () => {},
    source,
    noAgent,
    undefined,
    options,
  );
  const second = await createTaskVmProvider(
    upcloud,
    async () => {},
    source,
    noAgent,
    undefined,
    options,
  );
  assertEquals(generations, 1);
  assertEquals(first, second);
  assertEquals(events.indexOf("store") < events.indexOf("create"), true);
  assertEquals(
    events.slice(events.indexOf("store") + 1, events.indexOf("create"))
      .includes("read"),
    true,
  );
  assertEquals(f.calls.filter((call) => call.method === "POST").length, 1);
});
Deno.test("lost host-key store reply submits no create and retry reuses persisted pair", async () => {
  const f = fixture();
  let pair: { private: string; public: string } | null = null,
    generations = 0,
    lost = true;
  const source = {
    ...material,
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) => await use({ ...privateData, provider_bootstrap: pair }),
    storeSecretOutput: (value: Record<string, unknown>) => {
      pair = value.provider_bootstrap as { private: string; public: string };
      if (lost) {
        lost = false;
        return Promise.reject(new Error(secret));
      }
      return Promise.resolve();
    },
  };
  const options = {
    now: () => now,
    request: f.request,
    hostKey: () => {
      generations++;
      return Promise.resolve({ private: secret, public: key });
    },
  };
  await assertRejects(() =>
    createTaskVmProvider(
      upcloud,
      async () => {},
      source,
      noAgent,
      undefined,
      options,
    )
  );
  assertEquals(f.calls.some((call) => call.method === "POST"), false);
  await createTaskVmProvider(
    upcloud,
    async () => {},
    source,
    noAgent,
    undefined,
    options,
  );
  assertEquals(generations, 1);
  assertEquals(f.calls.filter((call) => call.method === "POST").length, 1);
});
Deno.test("an existing server without a staged host key cannot invent a replacement pin", async () => {
  const f = fixture();
  f.exists = true;
  let generations = 0;
  const source = {
    ...material,
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) => await use({ ...privateData, provider_bootstrap: null }),
  };
  await assertRejects(() =>
    createTaskVmProvider(upcloud, async () => {}, source, noAgent, undefined, {
      now: () => now,
      request: f.request,
      hostKey: () => {
        generations++;
        return Promise.resolve({ private: secret, public: key });
      },
    })
  );
  assertEquals(generations, 0);
  assertEquals(f.calls.some((call) => call.method !== "GET"), false);
});
Deno.test("malformed staged or generated host keys refuse before any provider mutation", async () => {
  const f = fixture();
  const source = {
    ...material,
    withPrivateMaterial: async (
      use: (value: Record<string, unknown>) => Promise<void>,
    ) =>
      await use({
        ...privateData,
        provider_bootstrap: { private: "malformed", public: key },
      }),
  };
  await assertRejects(() =>
    createTaskVmProvider(upcloud, async () => {}, source, noAgent, undefined, {
      now: () => now,
      request: f.request,
    })
  );
  assertEquals(f.calls, []);
});
