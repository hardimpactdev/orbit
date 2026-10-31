import { z } from "npm:zod@4.4.3";
import { runNodeAgent } from "../_lib/node-agent.ts";
import { renderAccountEnvironment } from "./dotenv.ts";

const maximum = 1048576;
const Key = z.string().regex(/^[A-Za-z_][A-Za-z0-9_]{0,254}$/);
const Target = z.object({
  base: z.string().startsWith("/").max(4096),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((x) =>
    x !== "root"
  ),
  environment: z.enum(["production", "development"]),
  domain: z.string().min(1).max(253).nullable(),
}).strict();
const Input = z.object({
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  environment: Target,
}).strict();
const Material = Target.extend({
  required_capacity: z.number().int().min(0),
  values: z.record(Key, z.string()),
  owned_keys: z.array(Key).max(1024),
  testing: z.object({
    values: z.record(Key, z.string()),
    managed_keys: z.array(Key).min(1).max(1024),
  }).strict().nullable(),
}).strict();
const Outcome = z.object({
  state: z.enum([
    "ok",
    "missing",
    "changed",
    "unchanged",
    "tracked",
    "tracking_unknown",
    "refused",
    "failed",
  ]),
  installed: z.boolean(),
  content: z.string().max(Math.ceil(maximum / 3) * 4).optional(),
});
type Port = {
  invoke?: typeof runNodeAgent;
  material: (action: (value: unknown) => Promise<void>) => Promise<void>;
  current: () => Promise<{ cancel_requested: boolean }>;
  now?: () => number;
};
type Result = {
  changed: boolean;
  synchronized_keys: string[];
  testing_status: "changed" | "unchanged" | "tracked" | null;
} | { error_code: string };
class Refused extends Error {}

/** Private callback performs all reads and effects locally; only a bounded public summary escapes. */
export async function synchronizeDeployEnvironment(
  raw: z.infer<typeof Input>,
  deadline: number,
  port: Port,
  signal?: AbortSignal,
): Promise<Result> {
  const input = Input.parse(raw),
    invoke = port.invoke ?? runNodeAgent,
    now = port.now ?? Date.now;
  let result: Result = { error_code: "env.sync_unconfirmed" };
  async function call(
    name: string,
    data: Record<string, unknown>,
    mutates: boolean,
  ) {
    if ((await port.current()).cancel_requested || signal?.aborted) {
      throw new Refused("operation.cancelled");
    }
    const remaining = Math.floor(deadline - now());
    if (!Number.isFinite(remaining) || remaining <= 0) {
      throw new Refused("deployment.deadline_exceeded");
    }
    return await invoke({
      name,
      input: data,
      pin: input.agent_sha256,
      sudo: true,
      mutates,
      deadlineMs: Math.min(remaining, 900000),
    }, signal);
  }
  try {
    await port.material(async (rawMaterial) => {
      // Shared private callbacks intentionally suppress exceptions; capture only safe codes here.
      try {
        const material = Material.parse(rawMaterial),
          target = input.environment;
        if (
          Object.keys(target).some((key) =>
            target[key as keyof typeof target] !==
              material[key as keyof typeof target]
          )
        ) throw new Refused("deployment.identity_changed");
        if (Object.keys(material.values).length === 0) {
          throw new Refused("env.configuration_missing");
        }
        const content = renderAccountEnvironment(
          material.values,
          target.environment,
          target.domain,
        );
        const testing = material.testing === null
          ? null
          : renderAccountEnvironment(
            material.testing.values,
            target.environment,
            target.domain,
          );
        const base = {
          base: target.base,
          user: target.user,
          maximum,
          require_home: target.environment === "production",
          required_capacity: material.required_capacity,
        };
        const inspected = z.object({
          preflight: Outcome,
          existing: Outcome.nullable(),
        }).parse(await call("instance.inspect-environment-sync", base, false));
        if (inspected.preflight.state !== "ok" || inspected.existing === null) {
          throw new Refused("env.sync_unconfirmed");
        }
        if (inspected.existing.state !== "missing") {
          if (
            inspected.existing.state !== "ok" ||
            inspected.existing.content === undefined
          ) throw new Refused("env.sync_unconfirmed");
          const bytes = Uint8Array.from(
            atob(inspected.existing.content),
            (c) => c.charCodeAt(0),
          );
          if (bytes.length > maximum) throw new Refused("env.sync_unconfirmed");
          const existing = new TextDecoder("utf-8", { fatal: true }).decode(
            bytes,
          );
          const owned = new Set([
            ...Object.keys(material.values),
            ...material.owned_keys,
          ]);
          const keys = existing.matchAll(
            /^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=/gm,
          );
          for (const match of keys) {
            if (!owned.has(match[1])) {
              throw new Refused("env.sync_would_drop_keys");
            }
          }
        }
        const main = Outcome.parse(
          await call("instance.write-environment", {
            ...base,
            mode: "write",
            file: ".env",
            managed_keys: [],
            content,
          }, true),
        );
        if (!["changed", "unchanged"].includes(main.state)) {
          throw new Refused("env.sync_unconfirmed");
        }
        let testing_status: "changed" | "unchanged" | "tracked" | null = null;
        if (testing !== null && material.testing !== null) {
          const written = Outcome.parse(
            await call("instance.write-environment", {
              ...base,
              mode: "merge",
              file: ".env.testing",
              managed_keys: material.testing.managed_keys,
              content: testing,
            }, true),
          );
          if (written.state === "tracking_unknown") {
            throw new Refused("env.testing_tracking_unknown");
          }
          if (
            written.state !== "changed" && written.state !== "unchanged" &&
            written.state !== "tracked"
          ) throw new Refused("env.sync_unconfirmed");
          testing_status = written.state;
        }
        result = {
          changed: main.state === "changed" || testing_status === "changed",
          synchronized_keys: Object.keys(material.values).sort(),
          testing_status,
        };
      } catch (error) {
        const code = error instanceof Refused
          ? error.message
          : error instanceof Error &&
              ["env.configuration_invalid", "env.reference_unavailable"]
                .includes(error.message)
          ? error.message
          : "env.sync_unconfirmed";
        result = { error_code: code };
      }
    });
    return result;
  } catch {
    return { error_code: "env.sync_unconfirmed" };
  }
}
