import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  convergeWireGuardHub,
  type HubPublicationInput,
} from "./wireguard_local.ts";
import {
  fakeNetworkSystem,
  type SystemCall as NodeCall,
} from "./network_test_support.ts";

const key = (n: number) =>
  btoa(String.fromCharCode(...new Uint8Array(32).fill(n)));
const snapshot: HubPublicationInput = {
  schema_version: 1,
  executor_node_id: 1,
  hub_id: 1,
  context_node_id: 212,
  excluded_node_id: 212,
  agent_sha256: "a".repeat(64),
};
const material = () => ({
  snapshot,
  address: "10.44.0.1/24",
  port: 51820,
  private_key: key(1),
  peers: [
    {
      node_id: 212,
      name: "removed",
      public_key: key(2),
      address: "10.44.0.212",
    },
    { node_id: 14, name: "gateway", public_key: key(3), address: "10.44.0.14" },
    { node_id: 1, name: "vpn", public_key: key(4), address: "10.44.0.1" },
  ],
});
Deno.test("hub prune uses one fixed pinned sudo program and excludes exact peer and hub", async () => {
  const calls: NodeCall[] = [];
  const result = await convergeWireGuardHub(
    snapshot,
    material(),
    fakeNetworkSystem((call) => {
      calls.push(call);
      return Promise.resolve({});
    }),
  );
  assertEquals(result, {
    schema_version: 1,
    hub_id: 1,
    status: "succeeded",
    projected: true,
  });
  assertEquals(calls.length, 1);
  assertEquals(calls[0].name, "wireguard.project-hub");
  assertEquals(
    calls[0].input.config,
    `[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nPrivateKey = ${
      key(1)
    }\n\n[Peer]\n# gateway\nPublicKey = ${
      key(3)
    }\nAllowedIPs = 10.44.0.14/32\n`,
  );
  assertEquals(JSON.stringify(result).includes(key(1)), false);
});
Deno.test("hub restore includes the exact current peer without changing effect placement", async () => {
  const restored = { ...snapshot, excluded_node_id: null };
  const data = { ...material(), snapshot: restored };
  await convergeWireGuardHub(
    restored,
    data,
    fakeNetworkSystem((call) => {
      assertEquals(String(call.input.config).includes(key(2)), true);
      return Promise.resolve({});
    }),
  );
});
Deno.test("hub rejects changed immutable participant before any effect", async () => {
  let calls = 0;
  await assertRejects(
    () =>
      convergeWireGuardHub(
        snapshot,
        {
          ...material(),
          snapshot: { ...snapshot, hub_id: 9 },
        },
        fakeNetworkSystem(() => {
          calls++;
          return Promise.resolve({});
        }),
      ),
    Error,
    "uncertain",
  );
  assertEquals(calls, 0);
});
Deno.test("hub known refusal returns only its closed safe code", async () => {
  const result = await convergeWireGuardHub(
    snapshot,
    material(),
    fakeNetworkSystem(() =>
      Promise.resolve({
        error_code: "wireguard.hub_config_invalid",
        stderr: key(1),
      })
    ),
  );
  assertEquals(result, {
    schema_version: 1,
    hub_id: 1,
    status: "failed",
    error_code: "wireguard.hub_config_invalid",
  });
});
Deno.test("hub transport uncertainty never propagates private diagnostic bytes", async () => {
  const error = await assertRejects(
    () =>
      convergeWireGuardHub(
        snapshot,
        material(),
        fakeNetworkSystem(() => Promise.reject(new Error(key(1)))),
      ),
    Error,
    "uncertain",
  );
  assertEquals(error.message.includes(key(1)), false);
});
Deno.test("hub malformed private input is sanitized before effect", async () => {
  const error = await assertRejects(
    () =>
      convergeWireGuardHub(snapshot, {
        ...material(),
        private_key: "private-invalid-sentinel",
      }),
    Error,
    "uncertain",
  );
  assertEquals(error.message.includes("sentinel"), false);
});
Deno.test("hub duplicate live public identity fails before mutation", async () => {
  const data = material();
  data.peers.push({ ...data.peers[1], node_id: 15 });
  await assertRejects(
    () =>
      convergeWireGuardHub(
        snapshot,
        data,
        fakeNetworkSystem(() => Promise.resolve({})),
      ),
    Error,
    "uncertain",
  );
});
Deno.test("hub unknown remote code leaves native execution uncertain", async () => {
  await assertRejects(
    () =>
      convergeWireGuardHub(
        snapshot,
        material(),
        fakeNetworkSystem(() =>
          Promise.resolve({
            error_code: "wireguard.unrecognized",
            secret: key(1),
          })
        ),
      ),
    Error,
    "uncertain",
  );
});

Deno.test("Gateway-placed TaskVM hub lower refuses missing SSH custody before any local command", async () => {
  const remote = { ...snapshot, executor_node_id: 14 };
  let effects = 0;
  await assertRejects(
    () =>
      convergeWireGuardHub(
        remote,
        { ...material(), snapshot: remote },
        fakeNetworkSystem(() => {
          effects++;
          return Promise.resolve({});
        }),
      ),
    Error,
    "uncertain",
  );
  assertEquals(effects, 0);
});

Deno.test("Gateway-placed TaskVM hub lower uses only the admitted pinned SSH session", async () => {
  const remote = { ...snapshot, executor_node_id: 14 };
  const connection = {
    target: {
      node_id: 1,
      host: "vpn.example.test",
      port: 22,
      user: "orbit",
      fingerprint: "SHA256:" + "A".repeat(43),
    },
    identity: "/private/existing-gateway-key",
  };
  let effects = 0;
  let local = 0;
  const result = await convergeWireGuardHub(
    remote,
    { ...material(), snapshot: remote, connection },
    fakeNetworkSystem(() => {
      local++;
      return Promise.resolve({});
    }),
    undefined,
    {
      environment: {
        createCelEnvironment: () => {
          throw new Error("unused fixture");
        },
      },
      ssh: (target, identity, _env, argv, stdin) => {
        effects++;
        assertEquals(target, connection.target);
        assertEquals(identity, connection.identity);
        assertEquals(argv.slice(0, 4), [
          "/usr/bin/sudo",
          "-n",
          "--",
          "/usr/bin/python3",
        ]);
        assertEquals(argv.some((value) => value.includes(key(1))), false);
        const data = JSON.parse(stdin!);
        assertEquals(data.name, "wireguard.project-hub");
        assertEquals(data.input.config.includes(key(1)), true);
        assertEquals(data.input.config.includes(key(2)), false);
        return Promise.resolve("{}");
      },
    },
  );
  assertEquals(result.status, "succeeded");
  assertEquals(effects, 1);
  assertEquals(local, 0);
  assertEquals(JSON.stringify(result).includes(connection.identity), false);
});

Deno.test("Gateway-placed TaskVM hub lower never defaults to local execution even with valid private material", async () => {
  const remote = { ...snapshot, executor_node_id: 14 };
  await assertRejects(
    () =>
      convergeWireGuardHub(remote, {
        ...material(),
        snapshot: remote,
        connection: {
          target: {
            node_id: 1,
            host: "vpn.example.test",
            port: 22,
            user: "orbit",
            fingerprint: "SHA256:" + "A".repeat(43),
          },
          identity: "/private/existing-key",
        },
      }),
    Error,
    "uncertain",
  );
});
