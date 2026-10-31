import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  cleanRouteResidue,
  type ResidueDependencies,
} from "./residue-local.ts";

const identity = { node_id: 18, agent_sha256: "a".repeat(64) };
const input = {
  rows: [{
    id: 9,
    route_id: 7,
    domain: "removed.orbit",
    steps: ["caddy", "php", "certificates", "firewall"],
    attempts: 0,
  }],
  caddy: { content: "remaining sites" },
  user: "orbit",
  php: {
    "8.5": "group=__orbit_converge_group__\nhome=/__orbit_converge_home__",
  },
  certificates: ["route-7", "route-7-router"],
  firewall: [{ operation: "remove", route_id: 7 }],
  ingress: { desired: [], remove: [], enable: false },
  dns: { records: "remaining", catalog: "{}" },
};
function fixture(refuse?: string) {
  const calls: { name: string; input?: unknown }[] = [];
  const deps: ResidueDependencies = {
    action(call) {
      calls.push(call);
      return Promise.resolve(
        call.name === refuse
          ? { error_code: "route.cleanup_refused" }
          : call.name === "caddy.publish"
          ? { result: "published" }
          : { changed: true },
      );
    },
    account(user) {
      calls.push({ name: "account", input: user });
      return Promise.resolve({ home: "/srv/orbit", group: "nodegroup" });
    },
    program(_source, payload) {
      const name = String(payload.name ?? payload.mode);
      calls.push({ name, input: payload });
      return Promise.resolve(
        name === refuse
          ? { error_code: "route.cleanup_refused" }
          : payload.mode
          ? { matches: true }
          : {},
      );
    },
    dns(value) {
      calls.push({ name: "dns", input: value });
      return Promise.resolve(
        refuse === "dns"
          ? { error_code: "route.cleanup_refused" }
          : { ready: true },
      );
    },
  };
  return { deps, calls };
}
Deno.test("offline cleanup publishes surviving sites and pools before removing exact leaves and rules", async () => {
  const { deps, calls } = fixture();
  assertEquals(await cleanRouteResidue(input, identity, deps), {
    changed: true,
  });
  assertEquals(calls.map((call) => call.name), [
    "caddy.publish",
    "account",
    "php.converge-shared-pools",
    "certificate.remove-leaf",
    "certificate.remove-leaf",
    "route",
    "rules",
    "dns",
  ]);
  assertEquals(calls[2].input, {
    configurations: { "8.5": "group=nodegroup\nhome=/srv/orbit" },
  });
  assertEquals(calls[3].input, {
    name: "certificate.remove-leaf",
    input: { scope: "route-7", home: "/srv/orbit", user: "orbit" },
  });
  assertEquals(calls[5].input, {
    mode: "route",
    operation: "remove",
    route_id: 7,
  });
});
Deno.test("offline cleanup always converges the empty final PHP pool set", async () => {
  const { deps, calls } = fixture();
  await cleanRouteResidue(
    {
      ...input,
      php: {},
      certificates: [],
      firewall: [],
      ingress: null,
      dns: null,
    },
    identity,
    deps,
  );
  assertEquals(calls.at(-1), {
    name: "php.converge-shared-pools",
    input: { configurations: {} },
  });
});
Deno.test("empty residue has no effects; a render refusal cannot remove leaves", async () => {
  const { deps, calls } = fixture();
  assertEquals(
    await cleanRouteResidue({ ...input, rows: [] }, identity, deps),
    { changed: false },
  );
  assertEquals(calls, []);
  assertEquals(
    await cleanRouteResidue(
      { ...input, caddy: { error_code: "caddy.render_failed" } },
      identity,
      deps,
    ),
    { error_code: "caddy.render_failed" },
  );
  assertEquals(calls, []);
});
Deno.test("every refused cleanup stage stops later effects and leaves completion to the parent", async () => {
  for (
    const stage of [
      "caddy.publish",
      "php.converge-shared-pools",
      "certificate.remove-leaf",
      "route",
      "rules",
      "dns",
    ]
  ) {
    const { deps, calls } = fixture(stage);
    assertEquals(await cleanRouteResidue(input, identity, deps), {
      error_code: "route.cleanup_refused",
    });
    assertEquals(calls.at(-1)?.name, stage);
  }
});
Deno.test("unknown residue data or missing publication proof cannot report success", async () => {
  const { deps, calls } = fixture();
  await assertRejects(() =>
    cleanRouteResidue(
      { ...input, certificates: ["app-instance-3"] },
      identity,
      deps,
    )
  );
  assertEquals(calls, []);
  await assertRejects(() =>
    cleanRouteResidue(input, identity, {
      ...deps,
      action: () => Promise.resolve({}),
    })
  );
});
