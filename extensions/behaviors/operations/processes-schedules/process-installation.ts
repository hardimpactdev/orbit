import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import { localAgent } from "./agent.ts";

const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Runtime = z.enum(["systemd", "docker"]);
const State = z.string().regex(/^[a-z][a-z_-]{0,63}$/);
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const Artifact = z.object({ runtime: Runtime, name: z.string() }).strict();
const Base = z.object({
  process_id: z.number().int().positive(),
  agent_sha256: Digest,
  name: z.string(),
  desired: z.enum(["running", "stopped"]),
  on_demand: z.boolean(),
  directory: z.string().startsWith("/").max(4096).optional(),
  retired: Artifact.nullable(),
});
export const ProcessInstallation = z.discriminatedUnion("runtime", [
  Base.extend({
    runtime: z.literal("systemd"),
    content_hash: Digest,
    activation_pending: z.boolean(),
  }).strict(),
  Base.extend({ runtime: z.literal("docker"), spec: Digest }).strict(),
]).superRefine((input, ctx) => {
  const valid = (runtime: string, name: string) =>
    new RegExp(
      `^orbit-process-${input.process_id}-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?` +
        (runtime === "systemd" ? "\\.service$" : "$"),
    ).test(name);
  if (
    !valid(input.runtime, input.name) ||
    (input.retired !== null &&
      (input.retired.runtime === input.runtime ||
        !valid(input.retired.runtime, input.retired.name)))
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Process artifact identity differs",
    });
  }
});
const Container = z.object({
  image: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._/:@-]*$/),
  command: z.array(z.string().max(65536)).min(1).max(4096),
  restart: z.enum(["no", "on-failure", "always", "unless-stopped"]),
  working_directory: z.string().startsWith("/").max(4096).nullable(),
  ports: z.array(z.string()).max(4096),
  mounts: z.array(
    z.object({
      source: z.string().min(1).max(4096),
      target: z.string().startsWith("/"),
      read_only: z.boolean(),
    }).strict(),
  ).max(4096),
  environment: z.record(z.string(), z.string()),
}).strict();
const Material = z.discriminatedUnion("runtime", [
  z.object({ runtime: z.literal("systemd"), content: z.string().min(1) })
    .strict(),
  z.object({
    runtime: z.literal("docker"),
    spec: Digest,
    definition: Container,
  }).strict(),
]);
type Outcome = { ready: true; state: string } | { error_code: string };

/** Reusable local DAG action. The parent owns traffic steps and final projection. */
export async function installProcess(
  input: z.infer<typeof ProcessInstallation>,
  privateMaterial: Pick<GatewayOperation, "withPrivateMaterial">,
  signal?: AbortSignal,
  invoke: typeof localAgent = localAgent,
): Promise<Outcome> {
  const admitted = ProcessInstallation.parse(input);
  const call = (
    name: Parameters<typeof localAgent>[0],
    data: Record<string, unknown>,
  ) => invoke(name, data, admitted.agent_sha256, signal);
  const artifact = {
    process_id: admitted.process_id,
    runtime: admitted.runtime,
    name: admitted.name,
  };
  let outcome: Outcome | undefined;
  try {
    await privateMaterial.withPrivateMaterial(async (material) => {
      const installation = Material.parse(material.installation);
      if (installation.runtime !== admitted.runtime) {
        throw new Error("Process runtime differs");
      }
      if (
        installation.runtime === "systemd" && admitted.runtime === "systemd"
      ) {
        const digest = await crypto.subtle.digest(
          "SHA-256",
          new TextEncoder().encode(installation.content),
        );
        const hash = Array.from(
          new Uint8Array(digest),
          (byte) => byte.toString(16).padStart(2, "0"),
        ).join("");
        if (hash !== admitted.content_hash) {
          throw new Error("Process unit differs");
        }
      }
      if (
        installation.runtime === "docker" && admitted.runtime === "docker" &&
        installation.spec !== admitted.spec
      ) throw new Error("Process spec differs");
      const refused = (result: Record<string, unknown>): boolean => {
        if (result.error_code === undefined) return false;
        outcome = { error_code: Code.parse(result.error_code) };
        return true;
      };
      if (admitted.directory !== undefined) {
        const release = await call("process.check-release", {
          directory: admitted.directory,
        });
        if (refused(release)) return;
        if (release.available !== true) {
          outcome = { error_code: "process.release_unavailable" };
          return;
        }
      }
      if (admitted.retired !== null) {
        if (
          refused(
            await call("process.remove", {
              process_id: admitted.process_id,
              ...admitted.retired,
              vite: null,
            }),
          )
        ) return;
      }
      if (
        installation.runtime === "systemd" && admitted.runtime === "systemd"
      ) {
        const staged = await call("process.stage-unit", {
          process_id: admitted.process_id,
          unit: admitted.name,
          content: installation.content,
        });
        if (refused(staged)) return;
        const stage = z.object({
          staged: z.boolean(),
          previous: z.boolean(),
          state: State.nullable(),
        }).parse(staged);
        const settled = stage.state ===
          (admitted.desired === "running" ? "active" : "inactive");
        if (stage.staged || admitted.activation_pending || !settled) {
          const activated = await call("process.activate-unit", {
            process_id: admitted.process_id,
            unit: admitted.name,
            desired: admitted.desired,
            on_demand: admitted.on_demand,
            change: stage.staged
              ? stage.previous ? "replace" : "create"
              : "reload",
          });
          if (refused(activated)) return;
          if (
            z.object({ backup_left: z.boolean() }).parse(activated).backup_left
          ) {
            outcome = { error_code: "process.systemd_converge_failed" };
            return;
          }
        }
      } else if (
        installation.runtime === "docker" && admitted.runtime === "docker"
      ) {
        if (
          refused(
            await call("process.converge-container", {
              process_id: admitted.process_id,
              container: admitted.name,
              desired: admitted.desired,
              spec: admitted.spec,
              ...installation.definition,
            }),
          )
        ) return;
      }
      const status = await call("process.status", artifact);
      if (!refused(status)) {
        outcome = { ready: true, state: State.parse(status.state) };
      }
    });
  } catch {
    throw new Error("Private Process installation did not complete");
  }
  if (outcome === undefined) {
    throw new Error("Private Process installation did not complete");
  }
  return outcome;
}
