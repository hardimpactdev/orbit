import { z } from "npm:zod@4.4.3";
import {
  type Context,
  Executor,
  ExecutorError,
  Operation,
} from "../../operation-runner/mod.ts";
import { Completion, ErrorCode } from "./contract.ts";

const Candidate = z.object({
  node_id: z.number().int().positive(),
  name: z.string(),
  address: z.ipv4(),
  selected: z.boolean(),
  caddy: z.boolean(),
  caddy_cleanup: z.boolean().default(false),
  fpm: z.boolean(),
  hosts: z.array(z.string()),
  instance_ids: z.array(z.number().int().positive()),
});
const Fleet = z.object({
  node_id: z.number().int().positive(),
  assignment_id: z.number().int().positive(),
  metrics_address: z.ipv4(),
  gateway_node_id: z.number().int().positive().nullable(),
  dns_node_id: z.number().int().positive().nullable(),
  fleet: z.array(Candidate),
  remove: z.boolean(),
  purge_data: z.boolean(),
  unreachable: z.boolean(),
  relocated: z.boolean().default(false),
});
const Child = Operation.extend({
  error_code: ErrorCode.nullable().optional(),
  result: z.record(z.string(), z.unknown()).nullable().optional(),
});
type Receipt = z.infer<typeof Child>;
const unavailable = new Set([
  "metrics.exporter_configuration_inspection_failed",
  "metrics.exporter_service_inspection_failed",
  "metrics.exporter_firewall_inspection_failed",
  "metrics.cadvisor_configuration_inspection_failed",
  "metrics.cadvisor_service_inspection_failed",
  "metrics.cadvisor_firewall_inspection_failed",
  "metrics.exporter_firewall_inactive",
  "metrics.cadvisor_firewall_inactive",
]);
class Pending extends Error {}
class Refused extends Error {
  constructor(readonly code: string, readonly cancelled = false) {
    super(code);
  }
}

/** Snapshots the admitted fleet before mutation and restores proven snapshots in reverse order. */
export async function metricsFleet(
  operation: z.infer<typeof Operation>,
  executor: Executor,
  worker: string,
  ctx: Context,
  checkLease: () => void,
) {
  const plan = Fleet.parse(operation.input);
  const path = String(operation.id);
  const rollback: {
    key: string;
    kind: string;
    node: z.infer<typeof Candidate>;
    source: Receipt;
    input: Record<string, unknown>;
  }[] = [];
  const degraded = new Set<number>();
  const serviceHealthy = new Set<number>();
  let committed = false;
  try {
    if (plan.remove && plan.gateway_node_id !== null) await publication(false);
    if (!plan.unreachable) {
      for (const component of ["node_exporter", "cadvisor"]) {
        const snapshots: {
          node: z.infer<typeof Candidate>;
          receipt: Receipt;
        }[] = [];
        for (const node of plan.fleet) {
          if (degraded.has(node.node_id)) continue;
          const receipt = await child(
            snapshotKey(component, node.node_id),
            `metrics.${component}_snapshot`,
            node.node_id,
            addresses(node),
            true,
          );
          if (receipt.status !== "succeeded") {
            if (
              node.node_id === plan.node_id ||
              !unavailable.has(receipt.error_code ?? "")
            ) fail(receipt);
            degraded.add(node.node_id);
          } else snapshots.push({ node, receipt });
        }
        for (const { node, receipt } of snapshots) {
          const action = node.selected ? "converge" : "remove";
          const key = `${component}-restore-${node.node_id}`;
          const mutation = await child(
            `${component}-apply-${node.node_id}`,
            `metrics.${component}_${action}`,
            node.node_id,
            addresses(node),
            true,
          );
          rollback.push({
            key,
            kind: `metrics.${component}_restore`,
            node,
            source: receipt,
            input: addresses(node),
          });
          if (mutation.status !== "succeeded") fail(mutation);
        }
      }
      const services: { node: z.infer<typeof Candidate>; receipt: Receipt }[] =
        [];
      for (const node of plan.fleet) {
        if (degraded.has(node.node_id)) continue;
        const receipt = await child(
          `service-snapshot-${node.node_id}`,
          "metrics.service_snapshot",
          node.node_id,
          serviceInput(node),
          true,
        );
        if (receipt.status === "succeeded") services.push({ node, receipt });
        else if (lockLost(receipt)) fail(receipt);
      }
      for (const { node, receipt } of services) {
        const input = serviceInput(node);
        const mutation = await child(
          `service-apply-${node.node_id}`,
          "metrics.service_converge",
          node.node_id,
          input,
          true,
        );
        if (mutation.status !== "succeeded") {
          if (lockLost(mutation)) {
            rollback.push({
              key: `service-restore-${node.node_id}`,
              kind: "metrics.service_restore",
              node,
              source: receipt,
              input,
            });
            fail(mutation);
          }
          const recovery = {
            key: `service-restore-${node.node_id}`,
            kind: "metrics.service_restore",
            node,
            source: receipt,
            input,
          };
          rollback.push(recovery);
          await restore(recovery, true);
          continue;
        }
        rollback.push({
          key: `service-restore-${node.node_id}`,
          kind: "metrics.service_restore",
          node,
          source: receipt,
          input,
        });
        if (rebuildsCaddy(node)) {
          await child(
            `service-caddy-${node.node_id}`,
            "route.caddy_converge",
            node.node_id,
            { reason: "metrics service projection" },
          );
        }
        serviceHealthy.add(node.node_id);
      }
      if (plan.remove) {
        await child("metrics-runtime", "metrics.runtime_remove", plan.node_id, {
          purge_data: plan.purge_data,
        });
      } else {
        const selected = plan.fleet.filter((node) =>
          node.selected && !degraded.has(node.node_id)
        );
        const targets = selected.map((node) => ({
          name: node.name,
          address: node.address,
        })).sort((a, b) => a.name.localeCompare(b.name));
        await child(
          "metrics-runtime",
          "metrics.runtime_converge",
          plan.node_id,
          {
            assignment_id: plan.assignment_id,
            targets,
            services: plan.fleet.filter((node) =>
              serviceHealthy.has(node.node_id)
            ),
            service_node_ids: [...serviceHealthy],
          },
        );
        committed = true;
        await child(
          "metrics-credentials-verified",
          "metrics.runtime_verify",
          plan.node_id,
          {},
        );
      }
    }
    if (
      !plan.remove &&
      ![
        "metrics.reconcile",
        "metrics.exporter_enable",
        "metrics.exporter_disable",
      ].includes(operation.kind)
    ) {
      await publication(true);
    }
    if (plan.remove && plan.gateway_node_id === null && !plan.unreachable) {
      await child(
        "publication-abandon",
        "metrics.publication_firewall",
        plan.node_id,
        { action: { operation: "abandon" } },
        true,
      );
    }
    const backend = plan.remove ? "absent" : "active";
    const result = z.object({ backend: z.literal(backend) }).parse(
      await executor.post(`${path}/run`, {
        worker,
        key: "integration-receipt",
        transport: "gateway",
        name: "metrics.receipt",
        input: { backend },
      }),
    );
    await executor.post(`${path}/step`, {
      worker,
      key: "integration-receipt:finish",
      name: "integration-receipt",
      status: "succeeded",
      result,
    });
    return await complete({ status: "succeeded", result, error_code: null });
  } catch (caught) {
    if (caught instanceof Pending) return { status: "waiting" };
    const error =
      caught instanceof ExecutorError && caught.code === "operation.cancelled"
        ? new Refused(caught.code, true)
        : caught;
    if (!(error instanceof Refused)) throw error;
    if (!committed) {
      try {
        for (const snapshot of rollback.toReversed()) await restore(snapshot);
      } catch (recovery) {
        if (recovery instanceof Pending) return { status: "waiting" };
        if (!(recovery instanceof Refused)) throw recovery;
        return await complete({
          status: "failed",
          result: {},
          error_code: "metrics.rollback_failed",
        });
      }
    }
    return await complete({
      status: error.cancelled ? "cancelled" : "failed",
      result: {},
      error_code: error.cancelled ? null : error.code,
    });
  }

  function snapshotKey(component: string, nodeId: number) {
    return component === "node_exporter" && nodeId === plan.node_id
      ? "metrics-cancellation-boundary"
      : `${component}-snapshot-${nodeId}`;
  }
  function addresses(node: z.infer<typeof Candidate>) {
    return { address: node.address, metrics_address: plan.metrics_address };
  }
  function serviceInput(node: z.infer<typeof Candidate>) {
    return {
      ...addresses(node),
      instance_ids: node.instance_ids,
      caddy: node.caddy,
      fpm: node.fpm,
    };
  }
  async function child(
    key: string,
    kind: string,
    node_id: number,
    _input: Record<string, unknown>,
    acceptFailure = false,
  ): Promise<Receipt> {
    checkLease();
    let raw: unknown;
    try {
      raw = await executor.post(`${path}/children`, { worker, key });
    } catch (error) {
      if (
        error instanceof ExecutorError && error.code === "operation.cancelled"
      ) {
        throw new Refused(error.code, true);
      }
      if (
        error instanceof ExecutorError &&
        error.code === "operation.child_forbidden"
      ) {
        const current = Operation.parse(
          await executor.post(`${path}/heartbeat`, { worker }),
        );
        checkLease();
        if (current.id !== operation.id || current.kind !== operation.kind) {
          throw new Error("metrics.operation_invalid");
        }
        if (current.cancel_requested) {
          throw new Refused("operation.cancelled", true);
        }
      }
      throw error;
    }
    const receipt = Child.parse(raw);
    checkLease();
    if (receipt.kind !== kind || receipt.node_id !== node_id) {
      throw new Error("metrics.child_invalid");
    }
    if (!["succeeded", "failed", "cancelled"].includes(receipt.status)) {
      await executor.post(`${path}/wait`, { worker, keys: [key] });
      throw new Pending();
    }
    if (receipt.status !== "succeeded" && !acceptFailure) fail(receipt);
    return receipt;
  }
  async function restore(item: typeof rollback[number], acceptFailure = false) {
    const source_key = snapshotKey(
      item.source.kind.split(".")[1].replace(/_snapshot$/, ""),
      item.node.node_id,
    );
    const restored = await child(item.key, item.kind, item.node.node_id, {
      ...item.input,
      source_operation_id: item.source.id,
      source_key,
      restore_key: item.key,
    }, acceptFailure);
    if (lockLost(restored)) fail(restored);
    if (item.kind === "metrics.service_restore" && rebuildsCaddy(item.node)) {
      const caddy = await child(
        `service-caddy-restore-${item.node.node_id}`,
        "metrics.service_caddy_restore",
        item.node.node_id,
        { restore_key: item.key },
        acceptFailure,
      );
      if (lockLost(caddy)) fail(caddy);
    }
  }
  function rebuildsCaddy(node: z.infer<typeof Candidate>) {
    return node.caddy || node.caddy_cleanup;
  }
  async function publication(enable: boolean) {
    const gateway = plan.gateway_node_id;
    if (gateway === null) throw new Refused("metrics.gateway_ambiguous");
    if (enable) {
      await child(
        "publication-certificate",
        "metrics.certificate_publish",
        gateway,
        {},
      );
      await child(
        "publication-firewall",
        "metrics.publication_firewall",
        plan.node_id,
        { action: { operation: "converge", gateway: await gatewayAddress() } },
      );
      await child("publication-caddy", "route.caddy_converge", gateway, {
        reason: "metrics publication",
      });
      if (plan.dns_node_id !== null) {
        await child(
          "publication-dns",
          "route.private_dns_publish",
          plan.dns_node_id,
          { reason: "metrics publication" },
        );
      }
    } else {
      if (plan.dns_node_id !== null) {
        await child(
          "publication-dns",
          "route.private_dns_publish",
          plan.dns_node_id,
          { reason: "metrics withdrawal" },
        );
      }
      await child("publication-caddy", "route.caddy_converge", gateway, {
        reason: "metrics withdrawal",
      });
      if (!plan.unreachable) {
        await child(
          "publication-firewall",
          "metrics.publication_firewall",
          plan.node_id,
          { action: { operation: "remove", gateway: await gatewayAddress() } },
        );
      }
      if (!plan.relocated) {
        await child(
          "publication-certificate",
          "metrics.certificate_remove",
          gateway,
          {},
        );
      }
    }
  }
  function gatewayAddress() {
    return Promise.resolve(z.ipv4().parse(operation.input.gateway_address));
  }
  async function complete(value: z.infer<typeof Completion>) {
    checkLease();
    const handle = await ctx.writeResource(
      "receipt",
      `operation-${path}`,
      value,
    );
    await executor.post(`${path}/complete`, { worker, ...value });
    return { status: value.status, dataHandles: [handle] };
  }
}
function fail(receipt: Receipt): never {
  throw new Refused(
    receipt.error_code ?? "metrics.child_failed",
    receipt.status === "cancelled",
  );
}
function lockLost(receipt: Receipt) {
  return ["node.lock_lost", "operation.lease_lost"].includes(
    receipt.error_code ?? "",
  ) || receipt.status === "cancelled";
}
