import { processSystem } from "../processes-schedules/process-system.ts";
import { systemCommand } from "../processes-schedules/system.ts";

type Container = {
  process_id: number;
  container: string;
  spec: string;
  image: string;
  restart: string;
  working_directory: string | null;
  ports: string[];
  mounts: { source: string; target: string; read_only: boolean }[];
  command: string[];
  desired: "running";
};
type Outcome = { ready: true } | { error_code: string; rolled_back: true };

/** Initialization belongs to this Swamp action; the server runs only its normal image command. */
export async function initializePlausible(
  desired: Container,
  environment: Record<string, string>,
  action: ReturnType<typeof processSystem> = processSystem(),
  signal?: AbortSignal,
  run: typeof systemCommand = systemCommand,
): Promise<Outcome> {
  for (const phase of ["createdb", "migrate"]) {
    const container = `${desired.container}-init-${phase}`;
    const digest = await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(`${desired.spec}:${phase}`),
    );
    const spec = Array.from(
      new Uint8Array(digest),
      (byte) => byte.toString(16).padStart(2, "0"),
    ).join("");
    try {
      const created = await action("process.converge-container", {
        ...desired,
        container,
        spec,
        environment,
        command: ["/entrypoint.sh", "db", phase],
        ports: [],
        restart: "no",
      }, signal);
      if (created.error_code !== undefined) {
        return {
          error_code: "analytics.initialization_failed",
          rolled_back: true,
        };
      }
      const completed = await run(
        "/usr/bin/sudo",
        ["-n", "--", "/usr/bin/docker", "container", "wait", container],
        undefined,
        signal,
        900_000,
      );
      const removed = await action("process.remove", {
        process_id: desired.process_id,
        runtime: "docker",
        name: container,
        vite: null,
        anonymous_volumes: true,
      });
      if (removed.error_code !== undefined) {
        return {
          error_code: "analytics.initialization_cleanup_failed",
          rolled_back: true,
        };
      }
      if (completed.code !== 0 || completed.stdout.trim() !== "0") {
        return {
          error_code: "analytics.initialization_failed",
          rolled_back: true,
        };
      }
    } catch {
      // Reconcile only the owned temporary identity, even when the caller was cancelled.
      try {
        const removed = await action("process.remove", {
          process_id: desired.process_id,
          runtime: "docker",
          name: container,
          vite: null,
          anonymous_volumes: true,
        });
        if (removed.error_code !== undefined) {
          return {
            error_code: "analytics.initialization_cleanup_failed",
            rolled_back: true,
          };
        }
      } catch {
        return {
          error_code: "analytics.initialization_cleanup_failed",
          rolled_back: true,
        };
      }
      return {
        error_code: "analytics.initialization_failed",
        rolled_back: true,
      };
    }
  }
  return { ready: true };
}
