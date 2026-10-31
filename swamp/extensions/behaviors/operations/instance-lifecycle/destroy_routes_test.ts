import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { composeDestroyRouteSnapshots } from "./destroy_routes.ts";

function member(instance_id = 1) {
  const checkout = `/srv/apps/proof/member-${instance_id}`;
  return {
    instance_id,
    source: {
      root: "/srv/apps",
      operation: "e282b872-9392-4d18-92e8-9bb946eb151c",
      member: String(instance_id),
      digest: "a".repeat(64),
      user: "orbit",
      group: "orbit",
      checkout,
      common_repository: checkout,
      layout: "checkout" as const,
      source_identity: "1:2",
    },
    branch: "main",
    receipt: "a".repeat(64),
    force: true,
    origin: "https://example.test/proof.git",
    worktrees: "",
    commit: "a".repeat(40),
    grouping_directory: "/srv/apps/proof",
    unresolved: false,
    independent_worktree: false,
    own_units: [],
  };
}
function fixture(ids = [1]) {
  const members = ids.map(member);
  return {
    node_id: 9,
    members,
    runtime: { processes: [], schedules: [] },
    database: {
      owner_instance_id: ids[0],
      intent_id: 4321,
      connection_ids: [],
      servers: [],
      sqlite: [],
    },
  };
}
function node(node_id = 9) {
  return {
    node_id,
    user: "orbit",
    agent_sha256: "a".repeat(64),
    caddy: {
      content: "# snapshot only",
      version: "a".repeat(32),
      listen_addresses: ["10.44.0.9"],
      unserved: true,
    },
    certificate_scopes: [] as string[],
    lan_route_ids: [] as number[],
  };
}
function route(instance_id = 1, route_ids: number[] = []) {
  return {
    schema: "route.withdrawal.v1",
    instance_id,
    route_ids,
    php: {
      node_id: 9,
      user: "orbit",
      agent_sha256: "a".repeat(64),
      configurations: {},
    },
    nodes: [{
      ...node(),
      certificate_scopes: [`app-instance-${instance_id}`],
      lan_route_ids: route_ids,
    }],
    dns: [] as Array<
      {
        node_id: number;
        user: string;
        agent_sha256: string;
        records: string;
        catalog: string;
        digest: string;
      }
    >,
  };
}
const vpn = () => ({
  node_id: 1,
  user: "orbit",
  agent_sha256: "a".repeat(64),
  records: "# records",
  catalog: "{}\n",
  digest: "c".repeat(64),
});

Deno.test("no-Route composition preserves local Caddy/certificate cleanup", () => {
  const result = composeDestroyRouteSnapshots(fixture(), [route()]);
  assertEquals(result.nodes.map((row) => row.node_id), [9]);
  assertEquals(result.nodes[0].certificate_scopes, ["app-instance-1"]);
  assertEquals(result.dns, []);
});

Deno.test("cascade composition deduplicates whole-Node publications and combines only owned cleanup", () => {
  const first = route(1, [10]), second = route(2, [11]);
  first.nodes[0].certificate_scopes.push("route-10");
  second.nodes[0].certificate_scopes.push("route-11");
  first.dns = [vpn()];
  second.dns = [vpn()];
  const result = composeDestroyRouteSnapshots(fixture([1, 2]), [first, second]);
  assertEquals(result.nodes.length, 1);
  assertEquals(result.nodes[0].certificate_scopes, [
    "app-instance-1",
    "app-instance-2",
    "route-10",
    "route-11",
  ]);
  assertEquals(result.nodes[0].lan_route_ids, [10, 11]);
  assertEquals(result.dns.map((row) => row.node_id), [1]);
});

Deno.test("workload and current/transition Routers keep separate exact placements", () => {
  const artifact = route(1, [10]);
  artifact.nodes.push({ ...node(18), certificate_scopes: ["route-10-router"] });
  artifact.nodes.push({
    ...node(17),
    certificate_scopes: ["route-10-router-hostname-change"],
  });
  artifact.dns = [vpn()];
  const result = composeDestroyRouteSnapshots(fixture(), [artifact]);
  assertEquals(result.nodes.map((row) => row.node_id), [9, 17, 18]);
  assertEquals(result.nodes[1].certificate_scopes, [
    "route-10-router-hostname-change",
  ]);
  assertEquals(result.nodes[2].certificate_scopes, ["route-10-router"]);
  assertEquals(result.dns[0].node_id, 1);
});

Deno.test("missing, duplicate or foreign member artifacts refuse composition", () => {
  for (const artifacts of [[], [route(2)], [route(), route()]]) {
    assertThrows(() => composeDestroyRouteSnapshots(fixture(), artifacts));
  }
});

Deno.test("foreign local publication account refuses source withdrawal", () => {
  const artifact = route();
  artifact.nodes[0].user = "other";
  assertThrows(() => composeDestroyRouteSnapshots(fixture(), [artifact]));
});

Deno.test("incomplete local cleanup and duplicate publication Nodes refuse composition", () => {
  for (const nodes of [[], [node(17)], [node(), node()]]) {
    assertThrows(() =>
      composeDestroyRouteSnapshots(fixture(), [{ ...route(), nodes }])
    );
  }
});

Deno.test("foreign certificate or LAN identities cannot enter another member's cleanup", () => {
  for (
    const changes of [
      { certificate_scopes: ["app-instance-2"] },
      { certificate_scopes: ["route-11"] },
      { lan_route_ids: [11] },
    ]
  ) {
    const artifact = route(1, [10]);
    Object.assign(artifact.nodes[0], changes);
    assertThrows(() => composeDestroyRouteSnapshots(fixture(), [artifact]));
  }
  const artifact = route(1, [10]);
  artifact.nodes.push({ ...node(17), certificate_scopes: ["app-instance-1"] });
  assertThrows(() => composeDestroyRouteSnapshots(fixture(), [artifact]));
});

Deno.test("a Route shared across admitted members is not silently deduplicated", () => {
  assertThrows(() =>
    composeDestroyRouteSnapshots(fixture([1, 2]), [
      route(1, [10]),
      route(2, [10]),
    ])
  );
});

Deno.test("conflicting whole-Node snapshots refuse rather than select one render", () => {
  for (const change of ["caddy", "dns", "identity"]) {
    const first = route(1, [10]), second = route(2, [11]);
    first.dns = [vpn()];
    second.dns = [vpn()];
    if (change === "caddy") {
      second.nodes[0].caddy.content = "# different snapshot";
    }
    if (change === "dns") second.dns[0].records = "# different records";
    if (change === "identity") second.nodes[0].agent_sha256 = "b".repeat(64);
    assertThrows(() =>
      composeDestroyRouteSnapshots(fixture([1, 2]), [first, second])
    );
  }
});
