import { z } from "npm:zod@4.4.3";
import { type SystemCommand, systemCommand } from "./system.ts";

const Id = z.number().int().positive();
const Port = z.number().int().min(1024).max(65535);
const Unit = z.string().regex(
  /^orbit-process-[1-9][0-9]*-[a-zA-Z0-9_.@-]+\.service$/,
);
const Marker = z.string().regex(
  /^\/dev\/shm\/orbit\/hibernation\/app-instance-[1-9][0-9]*\.awake$/,
);
const SafePath = z.string().startsWith("/").max(4096).refine((s) =>
  !/[\0\r\n]/.test(s) && !s.split("/").some((p) => p === "." || p === "..")
);
const Select = z.object({ preferred: Port, excluded: z.array(Port).max(65536) })
  .strict();
const Listener = z.object({
  unit: Unit,
  process_id: Id,
  port: Port,
  probe: z.boolean(),
}).strict();
const Traffic = z.discriminatedUnion("operation", [
  z.object({ operation: z.literal("suspend"), marker: Marker }).strict(),
  z.object({ operation: z.literal("awake"), marker: Marker }).strict(),
  z.object({
    operation: z.literal("environment"),
    application: SafePath,
    path: SafePath,
    instance_id: Id,
    marker: Marker,
    content: z.string().max(512),
  }).strict(),
]);

/** Probe locally and release immediately; the parent owns the selected-port projection. */
export async function selectVitePort(
  input: unknown,
): Promise<Record<string, unknown>> {
  const { preferred, excluded } = Select.parse(input);
  const held = new Set(excluded);
  for (const path of ["/proc/net/tcp", "/proc/net/tcp6"]) {
    let text: string;
    try {
      text = await Deno.readTextFile(path);
    } catch {
      continue;
    }
    for (const row of text.split("\n").slice(1)) {
      const fields = row.trim().split(/\s+/);
      if (fields[3] !== "06") {
        const hex = fields[1]?.split(":").at(-1);
        if (hex) held.add(parseInt(hex, 16));
      }
    }
  }
  for (let port = preferred; port <= 65535; port++) {
    if (held.has(port)) continue;
    try {
      let listener: Deno.TcpListener;
      try {
        listener = Deno.listen({ hostname: "::", port });
      } catch (error) {
        if (error instanceof Deno.errors.AddrInUse) continue;
        listener = Deno.listen({ hostname: "0.0.0.0", port });
      }
      listener.close();
      return { port };
    } catch (error) {
      if (!(error instanceof Deno.errors.AddrInUse)) {
        return { error_code: "vite.node_failed" };
      }
    }
  }
  return { error_code: "vite.ports_exhausted" };
}

export function viteSystem(run: SystemCommand = systemCommand) {
  const root = (
    program: string,
    args: readonly string[],
    stdin?: string,
    signal?: AbortSignal,
  ) =>
    run("/usr/bin/sudo", ["-n", "--", program, ...args], stdin, signal, 15_000);
  const must = async (
    program: string,
    args: readonly string[],
    stdin?: string,
    signal?: AbortSignal,
  ) => {
    const result = await root(program, args, stdin, signal);
    if (result.code !== 0) throw new Error("Vite system action failed");
    return result.stdout;
  };
  const metadata = async (path: string, signal?: AbortSignal) => {
    const result = await root(
      "/usr/bin/stat",
      ["--format=%F:%u:%a:%h", "--", path],
      undefined,
      signal,
    );
    if (result.code === 0) return result.stdout.trim();
    const absent = await root(
      "/usr/bin/test",
      ["!", "-e", path],
      undefined,
      signal,
    );
    const link = await root("/usr/bin/test", ["-L", path], undefined, signal);
    if (absent.code === 0 && link.code !== 0) return null;
    throw new Error("Vite artifact could not be inspected");
  };
  const directory = async (
    path: string,
    group = "root",
    signal?: AbortSignal,
  ) => {
    const current = await metadata(path, signal);
    if (current !== null && !/^directory:0:(?:711|755):\d+$/.test(current)) {
      throw new Error("Vite directory is foreign");
    }
    if (current !== null) return;
    await must(
      "/usr/bin/install",
      ["-d", "-o", "root", "-g", group, "-m", "0755", "--", path],
      undefined,
      signal,
    );
  };
  const file = async (path: string, marker?: string, signal?: AbortSignal) => {
    const current = await metadata(path, signal);
    if (current === null) return;
    if (!/^regular (?:empty )?file:0:(?:600|644):1$/.test(current)) {
      throw new Error("Vite artifact is foreign");
    }
    if (marker !== undefined) {
      const content = await must(
        "/usr/bin/cat",
        ["--", path],
        undefined,
        signal,
      );
      if (content.split("\n").filter((s) => s === marker).length !== 1) {
        throw new Error("Vite environment is foreign");
      }
    }
  };
  const owned = async (
    input: z.infer<typeof Listener>,
    signal?: AbortSignal,
  ) => {
    const content = await root(
      "/usr/bin/cat",
      ["--", `/etc/systemd/system/${input.unit}`],
      undefined,
      signal,
    );
    if (
      content.code !== 0 ||
      content.stdout.split("\n").filter((s) =>
          s === `X-Orbit-Process-ID=${input.process_id}`
        ).length !== 1
    ) return false;
    const group = await must(
      "/usr/bin/systemctl",
      ["show", "--property=ControlGroup", "--value", input.unit],
      undefined,
      signal,
    );
    const path = group.trim();
    const listeners = await must(
      "/usr/bin/ss",
      ["-H", "-ltnp", `sport = :${input.port}`],
      undefined,
      signal,
    );
    if (!path || !listeners.trim()) return false;
    for (const row of listeners.trim().split("\n")) {
      const pids = [...row.matchAll(/pid=(\d+)/g)].map((m) => m[1]);
      if (pids.length === 0) return false;
      for (const pid of pids) {
        const membership = await root(
          "/usr/bin/cat",
          ["--", `/proc/${pid}/cgroup`],
          undefined,
          signal,
        );
        if (
          membership.code !== 0 ||
          !membership.stdout.split("\n").some((line) => {
            const location = line.split(":").slice(2).join(":");
            return location === path || location.startsWith(path + "/");
          })
        ) return false;
      }
    }
    return true;
  };
  return async (
    name: string,
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>> => {
    try {
      if (name === "vite.select-port") return await selectVitePort(input);
      if (name === "vite.check-listener") {
        const admitted = Listener.parse(input);
        if (!await owned(admitted, signal)) {
          return { owned: false, ready: false };
        }
        let ready = false;
        if (admitted.probe) {
          const probe = await root(
            "/usr/bin/curl",
            [
              "--silent",
              "--noproxy",
              "*",
              "--max-time",
              "1",
              "--output",
              "/dev/null",
              "--write-out",
              "%{http_code}",
              `http://127.0.0.1:${admitted.port}/__orbit/vite/@vite/client`,
            ],
            undefined,
            signal,
          );
          ready = probe.code === 0 && probe.stdout === "200" &&
            await owned(admitted, signal);
        }
        return { owned: true, ready };
      }
      if (name !== "vite.set-traffic") {
        throw new Error("Unknown Vite system action");
      }
      const admitted = Traffic.parse(input);
      await directory("/dev/shm/orbit", "root", signal);
      await directory("/dev/shm/orbit/hibernation", "caddy", signal);
      await file(admitted.marker, undefined, signal);
      if (admitted.operation === "suspend") {
        await must(
          "/usr/bin/rm",
          ["-f", "--", admitted.marker],
          undefined,
          signal,
        );
      } else if (admitted.operation === "awake") {
        await must(
          "/usr/bin/install",
          [
            "-o",
            "root",
            "-g",
            "caddy",
            "-m",
            "0644",
            "/dev/stdin",
            admitted.marker,
          ],
          "",
          signal,
        );
      } else {
        if (
          admitted.path !==
            `/etc/orbit/vite/app-instance-${admitted.instance_id}.env` ||
          admitted.marker !==
            `/dev/shm/orbit/hibernation/app-instance-${admitted.instance_id}.awake` ||
          !new RegExp(
            `^# Orbit Instance ${admitted.instance_id}\\nORBIT_DEV_SERVER_PORT=([1-9][0-9]{3,4})\\n$`,
          ).test(admitted.content)
        ) throw new Error("Foreign Vite environment");
        for (
          const args of [["-x", "/usr/local/bin/vp"], [
            "-f",
            `${admitted.application}/package.json`,
          ], ["-d", `${admitted.application}/node_modules`]]
        ) {
          const result = await run(
            "/usr/bin/test",
            args,
            undefined,
            signal,
            10_000,
          );
          if (result.code !== 0) return { error_code: "vite.node_failed" };
        }
        await directory("/etc/orbit", "root", signal);
        await directory("/etc/orbit/vite", "root", signal);
        await file(
          admitted.path,
          `# Orbit Instance ${admitted.instance_id}`,
          signal,
        );
        await must(
          "/usr/bin/rm",
          ["-f", "--", admitted.marker],
          undefined,
          signal,
        );
        const candidate = `${admitted.path}.${crypto.randomUUID()}.pending`;
        try {
          await must(
            "/usr/bin/install",
            ["-o", "root", "-g", "root", "-m", "0600", "/dev/stdin", candidate],
            admitted.content,
            signal,
          );
          await must("/usr/bin/sync", ["-f", candidate], undefined, signal);
          await must(
            "/usr/bin/mv",
            ["-T", "--", candidate, admitted.path],
            undefined,
            signal,
          );
        } finally {
          await root("/usr/bin/rm", ["-f", "--", candidate], undefined, signal);
        }
      }
      return {};
    } catch {
      return { error_code: "vite.node_failed" };
    }
  };
}
