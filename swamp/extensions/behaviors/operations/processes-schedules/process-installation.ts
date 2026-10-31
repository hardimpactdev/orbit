import { z } from "npm:zod@4.4.3";
import { ProcessInstallation } from "./installation-input.ts";
export { ProcessInstallation } from "./installation-input.ts";
import type { GatewayOperation } from "../_lib/direct.ts";
import { processSystem, type ProcessSystemCommand } from "./process-system.ts";
import {
  processAccountContent,
  ProcessAccountHome,
} from "./process-account.ts";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const State = z.string().regex(/^[a-z][a-z_-]{0,63}$/);
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
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
export const ProcessInstallationMaterial = z.discriminatedUnion("runtime", [
  z.object({
    runtime: z.literal("systemd"),
    content: z.string().min(1),
    environment: z.string().min(1),
  })
    .strict(),
  z.object({
    runtime: z.literal("docker"),
    spec: Digest,
    definition: Container,
  }).strict(),
]);
export type ProcessInstallationOutcome = { ready: true; state: string } | {
  error_code: string;
};

type Invoke = (
  name: ProcessSystemCommand,
  input: Record<string, unknown>,
  admittedDigest: string | undefined,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
const localProcess: Invoke = (name, input, _digest, signal) =>
  processSystem()(name, input, signal);

/** Compose installation inside an existing native root, with no private fetch or projection. */
export function installProcessLocal(
  input: z.infer<typeof ProcessInstallation>,
  material: z.infer<typeof ProcessInstallationMaterial>,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<ProcessInstallationOutcome> {
  return installProcessWithTransport(
    input,
    {
      withPrivateMaterial: async (use) => await use({ installation: material }),
    },
    transport,
    signal,
  );
}

/** Existing parent callback and canonical transport; no child root or projection. */
export function installProcessWithTransport(
  input: z.infer<typeof ProcessInstallation>,
  privateMaterial: Pick<GatewayOperation, "withPrivateMaterial">,
  transport: SystemTransport,
  signal?: AbortSignal,
): Promise<ProcessInstallationOutcome> {
  const action = processSystem(
    async (command, args, stdin, abort, timeoutMs = 300_000) => {
      const result = await transport(
        { command, args, stdin, timeoutMs },
        abort,
      );
      return { code: result.success ? 0 : 1, stdout: result.stdout };
    },
  );
  return installProcess(
    input,
    privateMaterial,
    signal,
    (name, value, _digest, abort) => action(name, value, abort),
    (content, user, abort) =>
      processAccountContent(content, user, transport, abort),
  );
}

/** Reusable local DAG action. The parent owns traffic steps and final projection. */
export async function installProcess(
  input: z.infer<typeof ProcessInstallation>,
  privateMaterial: Pick<GatewayOperation, "withPrivateMaterial">,
  signal?: AbortSignal,
  invoke: Invoke = localProcess,
  resolveAccount: (
    content: string,
    user: string,
    signal?: AbortSignal,
  ) => Promise<string> = (content, user, abort) =>
    processAccountContent(content, user, undefined, abort),
): Promise<ProcessInstallationOutcome> {
  const admitted = ProcessInstallation.parse(input);
  const call = (
    name: ProcessSystemCommand,
    data: Record<string, unknown>,
  ) => invoke(name, data, admitted.agent_sha256, signal);
  const artifact = {
    process_id: admitted.process_id,
    runtime: admitted.runtime,
    name: admitted.name,
  };
  let outcome: ProcessInstallationOutcome | undefined;
  try {
    await privateMaterial.withPrivateMaterial(async (material) => {
      const installation = ProcessInstallationMaterial.parse(
        material.installation,
      );
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
        if (admitted.account_user !== undefined) {
          installation.content = await resolveAccount(
            installation.content,
            admitted.account_user,
            signal,
          );
        } else if (installation.content.includes(ProcessAccountHome)) {
          throw new Error("Process account template is unbound");
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
          environment: installation.environment,
          required_mount_paths: admitted.required_mount_paths ?? [],
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
            required_mount_paths: admitted.required_mount_paths ?? [],
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
