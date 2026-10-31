import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";
import { incusVmOperation, incusVolumeOperation } from "./provider_incus.ts";

const Name = z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/);
const Names = z.array(z.object({ name: Name }).passthrough()).max(4096);
type Location = { project: string; pool: string; cidr: string };
const refused = () => new Error("task_vm.host_command_failed");

/** Fixed Incus queries and canonical provider effects; callers never supply command source. */
export function incusSweepHost(
  host: Location,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
) {
  const query = async (args: string[]) => {
    signal?.throwIfAborted();
    const result = await invoke({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/incus", ...args],
      timeoutMs: 60000,
      maxOutputBytes: 4 * 1024 * 1024,
    }, signal);
    if (!result.success) throw refused();
    try {
      return JSON.parse(result.stdout);
    } catch {
      throw refused();
    }
  };
  const urls = async (path: string) =>
    z.array(z.string()).max(4096).parse(
      await query(["query", "--", path]),
    );
  const resource = (raw: string, prefix: string) => {
    const url = new URL(raw, "http://incus.invalid");
    if (
      url.origin !== "http://incus.invalid" ||
      !url.pathname.startsWith(prefix) ||
      [...url.searchParams.keys()].some((key) => key !== "project") ||
      url.searchParams.getAll("project").length > 1
    ) throw refused();
    const name = Name.parse(
      decodeURIComponent(url.pathname.slice(prefix.length)),
    );
    const project = Name.parse(url.searchParams.get("project") ?? "default");
    return { project, name };
  };
  return {
    names: async () =>
      Names.parse(
        await query(["--project", host.project, "list", "--format", "json"]),
      ).map((row) => row.name),
    volumes: async () =>
      z.array(z.object({ name: Name, type: z.string() }).passthrough()).max(
        4096,
      )
        .parse(
          await query([
            "--project",
            host.project,
            "storage",
            "volume",
            "list",
            host.pool,
            "--format",
            "json",
          ]),
        )
        .filter((row) => row.type === "custom").map((row) => row.name),
    images: async () =>
      z.array(
        z.object({ fingerprint: z.string().regex(/^[a-f0-9]{64}$/) })
          .passthrough(),
      ).max(4096)
        .parse(
          await query([
            "--project",
            host.project,
            "image",
            "list",
            "--format",
            "json",
          ]),
        ).map((row) => row.fingerprint),
    inventory: async () => {
      const instances = (await urls("/1.0/instances?all-projects=true")).map((
        url,
      ) => resource(url, "/1.0/instances/"));
      const volumes = [];
      for (const url of await urls("/1.0/storage-pools")) {
        const pool = resource(url, "/1.0/storage-pools/").name;
        const prefix = `/1.0/storage-pools/${
          encodeURIComponent(pool)
        }/volumes/custom/`;
        for (
          const volume of await urls(`${prefix.slice(0, -1)}?all-projects=true`)
        ) volumes.push({ ...resource(volume, prefix), pool });
      }
      if (volumes.length > 4096) throw refused();
      return { instances, volumes };
    },
    removeInstance: (name: string, guard: () => Promise<void>) =>
      incusVmOperation(
        {
          vm: { project: host.project, name, cidr: host.cidr },
          operation: { kind: "remove_instance" },
        },
        guard,
        invoke,
        signal,
      ),
    removeVolume: (name: string, guard: () => Promise<void>) =>
      incusVolumeOperation(
        {
          location: { project: host.project, pool: host.pool },
          operation: { kind: "remove", name },
        },
        guard,
        invoke,
        signal,
      ),
  };
}
