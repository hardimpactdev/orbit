import { assertEquals } from "jsr:@std/assert@1";
import { type DoctorNodeReport, mergeReports } from "./doctor_merge.ts";
import { issue, type NodeIntent, report } from "./doctor_contract.ts";

function node(id: number, resources: NodeIntent["resources"]): NodeIntent {
  return {
    id,
    name: `node-${id}`,
    platform: "linux",
    architecture: "amd64",
    user: "orbit",
    managed: true,
    parked: false,
    agent_sha256: "a".repeat(64),
    roles: [],
    resources,
  };
}
function result(
  id: number,
  families: DoctorNodeReport["families"],
): DoctorNodeReport {
  return {
    node_id: id,
    node_name: `node-${id}`,
    healthy: families.every((family) => family.status === "healthy"),
    families,
  };
}
Deno.test("related router/ingress findings and check resolutions belong to their admitted Instance", () => {
  const admitted = [
    node(7, { instances: [{ id: 41 }] }),
    node(8, {
      application_route_checks: [{
        id: 41,
        family: "instance",
        owner_node_id: 7,
      }],
    }),
  ];
  const drift = issue("instance", "instance.private_certificate_mismatch", {
    id: 41,
    name: "app",
  }, "Certificate differs.");
  const native = [
    result(7, [
      report("instance", 1, [drift], [41], {
        "41": ["instance.origin_mismatch"],
      }),
    ]),
    result(8, [
      report("instance", 0, [drift], [41], {
        "41": ["instance.private_certificate_mismatch"],
        "node:8": ["instance.node_unreachable"],
      }),
    ]),
  ];
  const original = structuredClone(native);
  const merged = mergeReports(admitted, native);
  assertEquals(merged[0].families[0].issues, [drift]);
  assertEquals(merged[0].families[0].observed_checks, {
    "41": ["instance.origin_mismatch", "instance.private_certificate_mismatch"],
  });
  assertEquals(merged[1].families[0].observed_checks, {
    "node:8": ["instance.node_unreachable"],
  });
  assertEquals(merged[1].families[0].observed_resources, []);
  assertEquals(merged[0].healthy, false);
  assertEquals(merged[1].healthy, true);
  assertEquals(native, original);
});
Deno.test("public custom-proxy and multi-workload Route findings retain admitted resource ownership", () => {
  const admitted = [
    node(7, {
      routes: [{ id: 51, owner_node_id: 7 }, { id: 52 }],
      application_route_checks: [{ id: 52, family: "route", owner_node_id: 7 }],
    }),
    node(8, {
      application_route_checks: [
        { id: 51, family: "route", owner_node_id: 7 },
        { id: 52, family: "route", owner_node_id: 8 },
        { id: 53, family: "route", owner_node_id: 7 },
      ],
    }),
  ];
  const findings = [51, 52, 53].map((id) =>
    issue("route", "route.websocket_collision", { id }, "Collision.")
  );
  const merged = mergeReports(admitted, [
    result(7, [report("route", 2, [])]),
    result(8, [
      report("route", 0, findings, [], { "51": ["route.caddy_mismatch"] }),
    ]),
  ]);
  assertEquals(merged[0].families[0].issues, findings);
  assertEquals(merged[0].families[0].checked, 2);
  assertEquals(merged[0].families[0].observed_checks, {
    "51": ["route.caddy_mismatch"],
  });
  assertEquals(merged[1].families[0].issues, []);
});
Deno.test("merge keeps unavailable owners visible and deduplicates by code, resource and finding kind", () => {
  const drift = issue(
    "instance",
    "instance.inspection_failed",
    { id: 41 },
    "Drift.",
  );
  const unknown = issue(
    "instance",
    "instance.inspection_failed",
    { id: 41 },
    "Unavailable.",
    "verifiable",
    "unverifiable",
    "unverifiable",
  );
  const merged = mergeReports([
    node(7, { instances: [{ id: 41 }] }),
    node(8, {}),
  ], [result(8, [report("instance", 0, [drift, drift, unknown], [], {})])]);
  assertEquals(merged[0].families[0].issues, [drift, unknown]);
  assertEquals(merged[0].families[0].status, "unverifiable");
  assertEquals(merged[0].healthy, false);
});
