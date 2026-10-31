import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  readRouteMutationParticipant,
  routeMutationActions,
  RouteMutationView,
} from "./mutation-steps.ts";
import { routeWorkloadLocal } from "./route-workload-local.ts";
import { certificateActions } from "../certificates/actions.ts";

const args = {
  operation_id: 9,
  kind: "cluster.attach",
  node_id: 3,
  target_node_id: 18,
  route_id: 7,
  run_id: "00000000-0000-4000-8000-000000000009",
};
function parent() {
  return {
    id: 9,
    kind: "cluster.attach",
    node_id: 3,
    status: "running",
    cancel_requested: false,
    input: {
      participant_node_id: 18,
      publication: { node_id: 18 },
      route_mutations: [{
        route_id: 7,
        node_ids: [3, 18],
        route: {
          route_id: 8,
          mode: "placement",
          domain: "candidate.test",
          node_ids: [3, 18],
          workloads: [],
          certificates: [],
          dns_node_ids: [],
          router_node_id: 18,
          ingress_node_id: null,
          production: false,
          public: false,
          web_root: null,
        },
        publication: { node_id: 18 },
        workloads: [{
          route_id: 8,
          instance_id: 21,
          node_id: 18,
          production: false,
          certificate_scope: "app-instance-21",
          source_access: { user: "orbit" },
        }],
        environment_workloads: [{
          route_id: 8,
          instance_id: 21,
          node_id: 18,
          production: false,
          certificate_scope: "app-instance-21",
        }, {
          route_id: 10,
          instance_id: 23,
          node_id: 18,
          production: false,
          certificate_scope: "app-instance-23",
        }],
        probes: [],
        cleanup: [{
          node_id: 18,
          user: "orbit",
          scope: "route-8-router-hostname-change",
          agent_sha256: "a".repeat(64),
          in_use: false,
        }],
        cleanup_lan: [{ operation: "retire", route_id: 8, keep: [] }],
        environment_fields: { "18": "environment_sync_node_0" },
      }],
    },
  };
}
Deno.test("Cluster mutation reads select one admitted Route and truthful foreign Node", async () => {
  const view = await readRouteMutationParticipant({
    read: () => Promise.resolve(parent()),
  }, args);
  assertEquals(view.route_id, 7);
  assertEquals(view.route.route_id, 8);
  assertEquals(view.publication.node_id, 18);
});
Deno.test("mutation cancellation recovery permits only unfinished exact-parent reads", async () => {
  const current = parent();
  current.cancel_requested = true;
  const gateway = { read: () => Promise.resolve(current) };
  await assertRejects(() => readRouteMutationParticipant(gateway, args));
  assertEquals(
    (await readRouteMutationParticipant(gateway, args, undefined, {
      recovery: true,
    })).route_id,
    7,
  );
  current.status = "succeeded";
  await assertRejects(() =>
    readRouteMutationParticipant(gateway, args, undefined, { recovery: true })
  );
});
Deno.test("mutation rejects changed root, kind, Route and placed workload before effects", async () => {
  for (
    const changed of [{ node_id: 209 }, { kind: "cluster.detach" }, {
      route_id: 99,
    }, { target_node_id: 1 }]
  ) {
    await assertRejects(() =>
      readRouteMutationParticipant({ read: () => Promise.resolve(parent()) }, {
        ...args,
        ...changed,
      })
    );
  }
  const current = parent();
  current.input.route_mutations[0].workloads[0].node_id = 1;
  await assertRejects(() =>
    readRouteMutationParticipant({ read: () => Promise.resolve(current) }, args)
  );
});
Deno.test("mutation steps reuse workload actions and consume only fresh Node cleanup", async () => {
  const events: unknown[] = [];
  const actions = routeMutationActions(
    {
      ...routeWorkloadLocal(),
      grantSourceAccess: (input) => {
        events.push(input);
        return Promise.resolve({ ready: true });
      },
    },
    undefined,
    certificateActions((input) => {
      events.push(input);
      return Promise.resolve(
        input.name.includes("inspect") ? { present: false } : { applied: true },
      );
    }),
    (input) => {
      events.push(input);
      return Promise.resolve({ matches: true });
    },
  );
  const view = RouteMutationView.parse(parent().input.route_mutations[0]);
  await actions.prepare(view);
  await actions.cleanup(view);
  assertEquals(events.length, 4);
  assertEquals(events[0], { user: "orbit" });
  assertEquals(events[3], { operation: "retire", route_id: 8, keep: [] });
  view.cleanup[0].node_id = 3;
  await assertRejects(() => actions.cleanup(view));
});
Deno.test("mutation private environment writes remain bounded to current placed workloads", async () => {
  const events: unknown[] = [];
  const actions = routeMutationActions({
    ...routeWorkloadLocal(),
    synchronizeEnvironment: (input) => {
      events.push(input);
      return Promise.resolve({ ready: true });
    },
  });
  const view = RouteMutationView.parse(parent().input.route_mutations[0]);
  await actions.synchronizeEnvironment(view, {
    environment_sync_node_0: [{
      instance_id: 21,
      name: "instance.write-development-url",
      input: { url: "private-value" },
    }],
  });
  assertEquals(events.length, 1);
  await actions.synchronizeEnvironment(view, {
    environment_sync_node_0: [{
      instance_id: 23,
      name: "instance.write-development-url",
      input: { url: "second-private-value" },
    }],
  });
  assertEquals(events.length, 2);
  await assertRejects(() =>
    actions.synchronizeEnvironment(view, {
      environment_sync_node_0: [{
        instance_id: 22,
        name: "instance.write-development-url",
        input: {},
      }],
    })
  );
});
