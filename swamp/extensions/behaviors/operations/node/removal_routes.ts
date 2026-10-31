import { z } from "npm:zod@4.4.3";
import { localAccount } from "./converge_local.ts";
import { RemovalNode } from "../routes/removal-local.ts";
import { removalActions } from "../routes/removal-local.ts";
import { CaddyPublication, publishCaddy } from "../routes/caddy.ts";
import { PhpPublication, publishPhp } from "../routes/php.ts";
import {
  convergeFirewall,
  convergeRouteLan,
  RouteLan,
} from "../routes/firewall-local.ts";
import {
  localRouteHost,
  routeWorkloadLocal,
} from "../routes/route-workload-local.ts";
import { type NetworkSystem, runNetworkSystem } from "./network_system.ts";
export const RemovalRouteInput = z.object({
  member: RemovalNode,
  publication: z.object({
    caddy_publication: CaddyPublication,
    php_publication: PhpPublication,
  }).passthrough(),
  workloads: z.array(
    z.object({
      instance_id: z.number().int().positive(),
      node_id: z.number().int().positive(),
      production: z.boolean(),
      runtime: z.record(z.string(), z.unknown()).nullable(),
      profile: z.record(z.string(), z.unknown()).nullable(),
      packages: z.record(z.string(), z.unknown()).nullable(),
    }).passthrough(),
  ).default([]),
  environment_field: z.string().regex(/^[a-z][a-z0-9_]{0,63}$/).nullable()
    .default(null),
  restore_lan: z.array(RouteLan).default([]),
  ingress_firewall: z.record(z.string(), z.unknown()).nullable(),
}).strict();
export type RemovalRouteDependencies = {
  workload?: Pick<
    ReturnType<typeof routeWorkloadLocal>,
    "prepareRuntime" | "synchronizeEnvironment"
  >;
  account?: typeof localAccount;
  withEnvironment?: (
    field: string,
    use: (writes: unknown) => Promise<void>,
  ) => Promise<void>;
};
async function applyNodeRoutes(
  raw: unknown,
  invoke: NetworkSystem = runNetworkSystem,
  signal?: AbortSignal,
  dependencies: RemovalRouteDependencies = {},
  restoring = false,
) {
  let input = RemovalRouteInput.parse(raw);
  if (input.member.home === "/__orbit_converge_home__") {
    const account = await (dependencies.account ?? localAccount)(
      input.member.user,
    );
    const replace = (value: unknown): unknown =>
      typeof value === "string"
        ? value.replaceAll("/__orbit_converge_home__", account.home).replaceAll(
          "__orbit_converge_group__",
          account.group,
        )
        : Array.isArray(value)
        ? value.map(replace)
        : value && typeof value === "object"
        ? Object.fromEntries(
          Object.entries(value).map(([key, item]) => [key, replace(item)]),
        )
        : value;
    input = RemovalRouteInput.parse(replace(input));
  }
  const member = input.member;
  if (
    input.publication.caddy_publication.node_id !== member.node_id ||
    input.publication.php_publication.node_id !== member.node_id
  ) {
    throw new Error("Route removal publication has a foreign Node binding");
  }
  const expected = [
    ...new Set([...member.production_instance_ids, ...member.url_instance_ids]),
  ].sort((a, b) => a - b);
  if (
    new Set(input.workloads.map((w) => w.instance_id)).size !==
      input.workloads.length ||
    JSON.stringify(
        input.workloads.map((w) => w.instance_id).sort((a, b) => a - b),
      ) !== JSON.stringify(expected) ||
    input.workloads.some((w) =>
      w.node_id !== member.node_id ||
      w.production !== member.production_instance_ids.includes(w.instance_id)
    ) ||
    (member.url_instance_ids.length > 0 &&
      (input.environment_field === null || !dependencies.withEnvironment))
  ) {
    throw new Error("The required Route workload cleanup is unavailable");
  }
  const workload = dependencies.workload ??
    routeWorkloadLocal(
      localRouteHost(),
      async (command, during) => {
        const output = await invoke(
          ["/usr/bin/sudo", ...command.args],
          command.stdin,
          during,
        );
        return { code: output.success ? 0 : 1, stdout: output.stdout };
      },
    );
  const runProgram = async (
    program: string,
    payload: Record<string, unknown>,
    during?: AbortSignal,
  ) => {
    const reply = await invoke(
      ["/usr/bin/python3", "-I", "-c", program],
      JSON.stringify(payload),
      during,
    );
    if (!reply.success) {
      throw new Error("The Route cleanup outcome is uncertain");
    }
    return z.record(z.string(), z.unknown()).parse(JSON.parse(reply.stdout));
  };
  const requireReady = (result: Record<string, unknown>) => {
    if (result.error_code !== undefined) {
      throw new Error(
        z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
      );
    }
  };
  requireReady(
    await publishCaddy(
      input.publication.caddy_publication,
      runProgram,
      signal,
    ),
  );
  if (member.shared_php) {
    requireReady(
      await publishPhp(input.publication.php_publication, runProgram, signal),
    );
  }
  for (const current of input.workloads) {
    signal?.throwIfAborted();
    await workload.prepareRuntime(current, signal);
  }
  if (member.url_instance_ids.length > 0) {
    await dependencies.withEnvironment!(
      input.environment_field!,
      async (rawWrites) => {
        const writes = z.array(
          z.object({
            instance_id: z.number().int().positive(),
            name: z.enum([
              "instance.write-environment",
              "instance.write-directory-url",
              "instance.write-development-url",
            ]),
            input: z.record(z.string(), z.unknown()),
          }).strict(),
        ).parse(rawWrites);
        if (
          writes.some((write) =>
            !member.url_instance_ids.includes(write.instance_id)
          )
        ) {
          throw new Error(
            "The private URL write has a foreign workload binding",
          );
        }
        await workload.synchronizeEnvironment(writes);
      },
    );
  }
  const lower = removalActions(runProgram);
  if (restoring) {
    for (const lan of input.restore_lan) {
      requireReady(await convergeRouteLan(lan, runProgram, signal));
    }
  } else {
    await lower.certificates(member, signal);
    await lower.lan(member);
  }
  if (member.ingress_firewall) {
    if (input.ingress_firewall === null) {
      throw new Error("Required ingress cleanup is unavailable");
    }
    requireReady(
      await convergeFirewall(input.ingress_firewall, runProgram, signal),
    );
  }
  return { ready: true as const };
}

export const removeNodeRoutes = (
  raw: unknown,
  invoke: NetworkSystem = runNetworkSystem,
  signal?: AbortSignal,
  dependencies: RemovalRouteDependencies = {},
) => applyNodeRoutes(raw, invoke, signal, dependencies);
export const restoreNodeRoutes = (
  raw: unknown,
  invoke: NetworkSystem = runNetworkSystem,
  signal?: AbortSignal,
  dependencies: RemovalRouteDependencies = {},
) => applyNodeRoutes(raw, invoke, signal, dependencies, true);
