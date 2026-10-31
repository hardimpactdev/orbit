import { localSystemCommand, type SystemTransport } from "./system_command.ts";
import { configureNodeAgent } from "./agent_configure_local.ts";
import { z } from "npm:zod@4.4.3";
import { NodeNetworkResultV1 } from "./network_direct.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import type { configureNodeWorker } from "../../node-worker/install.ts";
import type { FootprintAction } from "./footprint_local_actions.ts";
import {
  Agent,
  Code,
  Outcome,
  Snapshot,
  Stage,
} from "./converge_direct_contract.ts";

const ObjectInput = z.record(z.string(), z.unknown());
const Changed = z.object({ changed: z.boolean() });
const Caddy = z.object({
  content: z.string().max(4194304),
  version: z.string().max(256),
  listen_addresses: z.array(z.ipv4()).max(128),
  unserved: z.boolean(),
}).strict();
const RenderRefusal = z.object({
  error_code: z.literal("caddy.render_failed"),
  stage: z.literal("render"),
}).strict();
const Runtime = z.object({
  version: z.string().regex(/^[0-9]+\.[0-9]+$/),
  runtime_ini: z.string().max(65536),
  release: z.object({ id: z.string(), codenames: z.array(z.string()) })
    .strict(),
}).strict();
export type LocalDependencies = {
  system?: SystemTransport;
  action: FootprintAction;
  installWorker?: typeof configureNodeWorker;
  install: (
    snapshot: Snapshot,
    signal?: AbortSignal,
  ) => Promise<{ changed: boolean; error_code?: string }>;
  account: (user: string) => Promise<{ home: string; group: string }>;
  dns: (
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ) => Promise<Record<string, unknown>>;
  residue: (
    snapshot: Snapshot,
    signal?: AbortSignal,
  ) => Promise<Record<string, unknown>>;
  network: (
    input: NonNullable<Snapshot["network"]>,
    signal?: AbortSignal,
  ) => Promise<unknown>;
};

/** Fixed actions only. Dependencies expose the published Node/Fleet boundaries for composition. */
export function footprintActions(deps: LocalDependencies) {
  return async (
    stage: Stage,
    snapshot: Snapshot,
    gateway: GatewayOperation,
    binaryChanged: boolean,
    signal?: AbortSignal,
  ): Promise<Outcome> => {
    signal?.throwIfAborted();
    const artifact = snapshot.artifacts.find((a) =>
      a.name === (stage === "agent-install" ? "agent" : stage)
    );
    const entry = (changed: boolean) => ({
      stage,
      ok: true,
      entry: {
        name: artifact!.name,
        status: changed ? "applied" as const : "unchanged" as const,
      },
    });
    const refused = (code: unknown): Outcome => ({
      stage,
      ok: false,
      error_code: Code.parse(code),
      ...(artifact ? { failed_artifact: artifact.name } : {}),
    });
    if (!artifact) return { stage, ok: true };
    if (!artifact.required) {
      return stage === "agent-install"
        ? { stage, ok: true, binary_changed: false }
        : entry(false);
    }
    const payload = snapshot.payloads[artifact.name];
    const call = (
      name: Parameters<FootprintAction>[0]["name"],
      input: Record<string, unknown>,
    ) => deps.action({ name, input }, signal);
    if (stage === "agent-install") {
      const result = await deps.install(snapshot, signal);
      return result.error_code
        ? refused(result.error_code)
        : { stage, ok: true, binary_changed: result.changed };
    }
    if (stage === "agent") {
      const input = Agent.parse(payload);
      let result: Awaited<ReturnType<typeof configureNodeAgent>> | undefined;
      await gateway.withPrivateMaterial(async (material) => {
        result = await configureNodeAgent(
          input,
          snapshot.agent_sha256,
          binaryChanged,
          material,
          deps.system ?? localSystemCommand,
          deps.account,
          signal,
          deps.installWorker,
        );
      });
      if (!result) throw new Error("Agent configuration has no proven safe result");
      return result.error_code
        ? refused(result.error_code)
        : entry(result.changed);
    }
    if (stage === "peer-dns") {
      if (snapshot.network === null) {
        throw new Error("Required peer DNS has no admitted network action");
      }
      const network = NodeNetworkResultV1.parse(
        await deps.network(snapshot.network, signal),
      );
      return network.status === "failed"
        ? refused(network.error_code)
        : entry(network.status === "applied");
    }
    if (stage === "caddy") {
      const render = RenderRefusal.safeParse(payload);
      if (render.success) {
        return {
          stage,
          ok: true,
          entry: {
            name: "caddy",
            status: "skipped",
            reason: "caddy_render_refused",
          },
        };
      }
      const result = await call("caddy.publish", Caddy.parse(payload));
      if (
        result.error_code === "caddy.publication_failed" &&
        result.stage === "validate"
      ) {
        return {
          stage,
          ok: true,
          entry: {
            name: "caddy",
            status: "skipped",
            reason: "caddy_validate_refused",
          },
          ...(snapshot.first_visit && !artifact.repair
            ? { deferred_error: "node.footprint_caddy_failed" as const }
            : {}),
        };
      }
      if (result.error_code) return refused(result.error_code);
      return entry(
        z.object({ result: z.enum(["published", "unchanged"]) }).parse(result)
          .result === "published",
      );
    }
    if (stage === "php-runtime") {
      const input = z.object({ configurations: z.array(Runtime).max(16) })
        .strict().parse(payload);
      let changed = false;
      for (const configuration of input.configurations) {
        const result = await call("php.configure-runtime", configuration);
        if (result.error_code) return refused(result.error_code);
        changed = Changed.parse(result).changed || changed;
      }
      return entry(changed);
    }
    if (stage === "private-dns") {
      const input = z.object({
        listener: ObjectInput,
        publication: ObjectInput,
      }).strict().parse(payload);
      const listener = await call(
        "node.install-private-dns-listener",
        input.listener,
      );
      if (listener.error_code) return refused(listener.error_code);
      const installed = z.object({
        installed: z.literal(true),
        changed: z.boolean(),
      }).parse(listener);
      const publication = await deps.dns(input.publication, signal);
      if (publication.error_code) return refused(publication.error_code);
      z.object({ ready: z.literal(true) }).parse(publication);
      return entry(installed.changed || publication.changed !== false);
    }
    if (stage === "route-residue") {
      const result = await deps.residue(snapshot, signal);
      if (result.error_code) {
        return {
          stage,
          ok: true,
          entry: {
            name: "route-residue",
            status: "skipped",
            reason: "route_residue_cleanup_failed",
          },
        };
      }
      return entry(Changed.parse(result).changed);
    }
    const command = stage === "caddy-package"
      ? "caddy.install-package"
      : "node.publish-tmpfiles";
    const result = await call(command, ObjectInput.parse(payload));
    return result.error_code
      ? refused(result.error_code)
      : entry(Changed.parse(result).changed);
  };
}

import { convergeLocalDependencies } from "./converge_local.ts";
export const convergeFootprintStage = footprintActions(
  convergeLocalDependencies,
);
