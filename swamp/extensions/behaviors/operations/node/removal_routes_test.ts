import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { removeNodeRoutes, restoreNodeRoutes } from "./removal_routes.ts";
import { localRouteHost } from "../routes/route-workload-local.ts";
import { caddyProgram } from "../routes/caddy-program.ts";
import { PhpSharedProgram } from "../routes/php-shared-program.ts";
const member = {
  node_id: 13,
  user: "managed",
  home: "/srv/managed",
  agent_sha256: "a".repeat(64),
  certificate_scopes: ["route-7"],
  lan_route_ids: [7],
  shared_php: true,
  production_instance_ids: [] as number[],
  url_instance_ids: [] as number[],
  ingress_firewall: true,
};
async function input() {
  const content = "owned caddy content\n";
  const digest = Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(content)),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  return {
    member,
    publication: {
      caddy_publication: {
        node_id: 13,
        user: "managed",
        agent_sha256: "a".repeat(64),
        caddy: {
          content,
          version: digest.slice(0, 32),
          listen_addresses: [],
          unserved: false,
        },
      },
      php_publication: { node_id: 13, configurations: {} },
    },
    ingress_firewall: { desired: [], remove: [], enable: false },
  };
}
Deno.test("Node Route cleanup composes canonical whole publications then certificate LAN and ingress cleanup", async () => {
  const calls: string[] = [];
  const result = await removeNodeRoutes(await input(), (argv, stdin) => {
    const data = JSON.parse(stdin!);
    let reply: unknown;
    if (argv[3] === caddyProgram) {
      calls.push("caddy");
      reply = { result: "published" };
    } else if (argv[3] === PhpSharedProgram) {
      calls.push("php");
      reply = { changed: true, missing_pools: [] };
    } else if (data.name) {
      calls.push(data.name);
      reply = data.name === "certificate.inspect-leaf"
        ? { present: false }
        : {};
    } else {
      calls.push(`firewall:${data.mode}`);
      reply = { matches: true };
    }
    return Promise.resolve({ success: true, stdout: JSON.stringify(reply) });
  });
  assertEquals(result, { ready: true });
  assertEquals(calls, [
    "caddy",
    "php",
    "certificate.remove-leaf",
    "certificate.inspect-leaf",
    "firewall:route",
    "firewall:rules",
  ]);
});
Deno.test("foreign publication and unavailable required dedicated cleanup refuse before effects", async () => {
  for (const kind of ["foreign", "dedicated"]) {
    const value = await input();
    let effects = 0;
    if (kind === "foreign") value.publication.php_publication.node_id = 209;
    else value.member = { ...value.member, production_instance_ids: [1] };
    await assertRejects(() =>
      removeNodeRoutes(value, () => {
        effects++;
        return Promise.resolve({ success: true, stdout: "{}" });
      })
    );
    assertEquals(effects, 0);
  }
});

Deno.test("dedicated PHP and URL cleanup use canonical workload lowers with private writes confined to the callback", async () => {
  const value = await input();
  const events: string[] = [];
  const material = [{
    instance_id: 51,
    name: "instance.write-directory-url",
    input: { private: "synthetic-private-url-material" },
  }];
  const payload = {
    ...value,
    member: {
      ...value.member,
      production_instance_ids: [51],
      url_instance_ids: [51],
    },
    workloads: [{
      instance_id: 51,
      node_id: 13,
      production: true,
      runtime: { mode: "converge" },
      packages: {},
      profile: {},
    }],
    environment_field: "removal_environment_0",
  };
  const result = await removeNodeRoutes(
    payload,
    (argv, stdin) => {
      const data = JSON.parse(stdin!);
      let result: unknown = {};
      if (argv[3] === caddyProgram) {
        events.push("caddy");
        result = { result: "published" };
      } else if (argv[3] === PhpSharedProgram) {
        events.push("shared-php");
        result = { changed: true, missing_pools: [] };
      } else if (data.name === "certificate.inspect-leaf") {
        result = {
          present: false,
        };
      } else if (!data.name) result = { matches: true };
      return Promise.resolve({ success: true, stdout: JSON.stringify(result) });
    },
    undefined,
    {
      workload: {
        prepareRuntime(raw) {
          assertEquals(raw, payload.workloads[0]);
          events.push("dedicated-php");
          return Promise.resolve({ ready: true });
        },
        synchronizeEnvironment(writes) {
          assertEquals(writes, material);
          events.push("url");
          return Promise.resolve({ ready: true });
        },
      },
      withEnvironment: async (field, use) => {
        assertEquals(field, "removal_environment_0");
        events.push("private-read");
        await use(material);
      },
    },
  );
  assertEquals(events, [
    "caddy",
    "shared-php",
    "dedicated-php",
    "private-read",
    "url",
  ]);
  assertEquals(result, { ready: true });
  assertEquals(
    JSON.stringify(result).includes("synthetic-private-url-material"),
    false,
  );
});
Deno.test("missing, duplicate or foreign public workload capture refuses before any publication", async () => {
  const base = await input();
  const good = {
    instance_id: 51,
    node_id: 13,
    production: true,
    runtime: {},
    packages: {},
    profile: {},
  };
  for (
    const workloads of [[], [good, good], [{ ...good, node_id: 209 }], [{
      ...good,
      instance_id: 52,
    }], [{ ...good, production: false }]]
  ) {
    let effects = 0;
    await assertRejects(() =>
      removeNodeRoutes({
        ...base,
        member: { ...base.member, production_instance_ids: [51] },
        workloads,
      }, () => {
        effects++;
        return Promise.resolve({ success: true, stdout: "{}" });
      })
    );
    assertEquals(effects, 0);
  }
});
Deno.test("a foreign private URL write is refused before the canonical environment writer", async () => {
  const base = await input();
  let writes = 0;
  await assertRejects(() =>
    removeNodeRoutes(
      {
        ...base,
        member: { ...base.member, url_instance_ids: [51] },
        environment_field: "removal_environment_0",
        workloads: [{
          instance_id: 51,
          node_id: 13,
          production: false,
          runtime: null,
          profile: null,
          packages: null,
        }],
      },
      (argv, _stdin) =>
        Promise.resolve({
          success: true,
          stdout: JSON.stringify(
            argv[3] === caddyProgram
              ? { result: "published" }
              : { changed: true, missing_pools: [] },
          ),
        }),
      undefined,
      {
        workload: {
          prepareRuntime: () => Promise.resolve({ ready: true }),
          synchronizeEnvironment: () => {
            writes++;
            return Promise.resolve({ ready: true });
          },
        },
        withEnvironment: async (_field, use) => {
          await use([{
            instance_id: 209,
            name: "instance.write-development-url",
            input: {},
          }]);
        },
      },
    )
  );
  assertEquals(writes, 0);
});
Deno.test("canonical Route host private writes use the injected placed runner with stdin and no worker-local staging path", async () => {
  const calls: { binary: string; args: string[]; input?: string }[] = [];
  const host = localRouteHost((binary, args, options) => {
    calls.push({ binary, args, input: options?.input });
    return Promise.resolve({ success: true, code: 0, stdout: "" });
  });
  await host.write(
    "/srv/owner/.env",
    "synthetic-private-route-environment",
    "owner",
    0o600,
  );
  assertEquals(calls.map((c) => c.binary), [
    "/usr/bin/install",
    "/usr/bin/mv",
    "/usr/bin/rm",
  ]);
  assertEquals(calls[0].input, "synthetic-private-route-environment");
  assertEquals(calls[0].args.includes("/dev/stdin"), true);
  assertEquals(
    JSON.stringify(calls.map((c) => c.args)).includes(
      "synthetic-private-route-environment",
    ),
    false,
  );
});

Deno.test("restoration republishes serving state and never removes the restored certificates or LAN routes", async () => {
  const calls: string[] = [];
  const result = await restoreNodeRoutes(await input(), (argv, stdin) => {
    if (argv[3] === caddyProgram) {
      calls.push("caddy");
      return Promise.resolve({
        success: true,
        stdout: '{"result":"published"}',
      });
    }
    if (argv[3] === PhpSharedProgram) {
      calls.push("php");
      return Promise.resolve({
        success: true,
        stdout: '{"changed":true,"missing_pools":[]}',
      });
    }
    const data = JSON.parse(stdin!);
    calls.push(data.name ?? `firewall:${data.mode}`);
    return Promise.resolve({ success: true, stdout: '{"matches":true}' });
  });
  assertEquals(result, { ready: true });
  assertEquals(calls, ["caddy", "php", "firewall:rules"]);
});
