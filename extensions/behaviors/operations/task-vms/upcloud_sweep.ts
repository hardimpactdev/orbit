import { z } from "npm:zod@4.4.3";
import {
  currentSweep,
  type SweepGateway,
  type SweepInput,
  SweepRefused,
} from "./sweep.ts";

const UUID = z.string().uuid();
const Label = z.object({ key: z.string(), value: z.string() });
const Listed = z.object({
  uuid: UUID,
  title: z.string(),
  labels: z.object({ label: z.array(Label) }).optional(),
});
const Server = Listed.extend({
  hostname: z.string(),
  state: z.enum(["started", "stopped", "maintenance", "error"]),
});
type Candidate = { uuid: string; name: string; label: string; value: string };
export type CloudRequest = (
  method: "GET" | "POST" | "DELETE",
  path: string,
  body?: Record<string, unknown>,
  missing?: boolean,
) => Promise<Record<string, unknown> | null>;
const Origin = "https://api.upcloud.com/1.3/";

/** Fixed provider origin; private material never enters resources, errors, argv or request URLs. */
export function cloudRequest(
  gateway: SweepGateway,
  signal?: AbortSignal,
  fetcher = fetch,
): CloudRequest {
  return async (method, path, body, missing = false) => {
    let refused = false;
    let answer: Record<string, unknown> | null | undefined;
    await gateway.withPrivateMaterial(async (material) => {
      const token = z.string().regex(/^ucat_[A-Za-z0-9_-]+$/).parse(
        material.upcloud_token,
      );
      const response = await fetcher(Origin + path, {
        method,
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
          ...(body ? { "Content-Type": "application/json" } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
        redirect: "error",
        signal: signal
          ? AbortSignal.any([signal, AbortSignal.timeout(60000)])
          : AbortSignal.timeout(60000),
      });
      if (missing && response.status === 404) {
        await response.body?.cancel();
        answer = null;
        return;
      }
      if (!response.ok) {
        await response.body?.cancel();
        refused = true;
        return;
      }
      const reader = response.body?.getReader();
      const parts: Uint8Array[] = [];
      let size = 0;
      if (reader) {
        try {
          for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            size += value.length;
            if (size > 4 * 1024 * 1024) throw new SweepRefused();
            parts.push(value);
          }
        } finally {
          await reader.cancel();
        }
      }
      const bytes = new Uint8Array(size);
      let offset = 0;
      for (const part of parts) {
        bytes.set(part, offset);
        offset += part.length;
      }
      const text = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
      answer = text.trim() === ""
        ? {}
        : z.record(z.string(), z.unknown()).parse(JSON.parse(text));
    });
    if (refused || answer === undefined) throw new SweepRefused();
    return answer;
  };
}
function labelValue(
  labels: { label: z.infer<typeof Label>[] } | undefined,
  key: string,
): string | null {
  const matches = labels?.label.filter((label) => label.key === key) ?? [];
  if (matches.length > 1) throw new SweepRefused();
  return matches[0]?.value ?? null;
}
function stale(stamp: string, input: SweepInput, now: number) {
  if (!/^[0-9]{14}$/.test(stamp) || input.running_stamps.includes(stamp)) {
    return false;
  }
  const iso = `${stamp.slice(0, 4)}-${stamp.slice(4, 6)}-${stamp.slice(6, 8)}T${
    stamp.slice(8, 10)
  }:${stamp.slice(10, 12)}:${stamp.slice(12, 14)}Z`;
  const date = Date.parse(iso);
  return Number.isFinite(date) &&
    new Date(date).toISOString().replace(/[-:TZ]/g, "").slice(0, 14) ===
      stamp &&
    date < now - 2 * 3600000;
}
function owned(candidate: Candidate, input: SweepInput, now: number) {
  if (candidate.label === "orbit-task-vm") {
    return /^tvm-[0-9]+$/.test(candidate.name) &&
      candidate.name === `tvm-${candidate.value}` &&
      !input.live_names.includes(candidate.name);
  }
  return candidate.label === "orbit-task-image" &&
    (candidate.name === `tvm-image-${candidate.value}` ||
      candidate.name === `tvm-image-${candidate.value}-smoke`) &&
    stale(candidate.value, input, now);
}
async function find(request: CloudRequest, candidate: Candidate) {
  const raw = await request("GET", `server/${candidate.uuid}`, undefined, true);
  if (raw === null) return null;
  const value = Server.parse(raw.server);
  if (
    value.uuid !== candidate.uuid || value.title !== candidate.name ||
    value.hostname !== candidate.name ||
    labelValue(value.labels, candidate.label) !== candidate.value
  ) throw new SweepRefused();
  return value;
}
const pause = (signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    signal?.throwIfAborted();
    const abort = () => {
      clearTimeout(timer);
      reject(signal?.reason);
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", abort);
      resolve();
    }, 3000);
    signal?.addEventListener("abort", abort, { once: true });
  });

/** Provider stop/delete/absence waits stay in this native root; re-entry observes before mutation. */
export async function sweepUpCloud(
  input: SweepInput,
  gateway: SweepGateway,
  signal?: AbortSignal,
  request = cloudRequest(gateway, signal),
  wait = pause,
  now = Date.now,
) {
  const deadline = input.deadline_at * 1000; // Existing host-sweep budget, owned entirely by this native step.
  const check = async (settling = false) => {
    if (now() >= deadline) throw new SweepRefused();
    return await currentSweep(input, gateway, signal, settling);
  };
  const labelled = async (label: string) => {
    const raw = await request(
      "GET",
      `server?label=${encodeURIComponent(label)}`,
    );
    const rows =
      z.object({ servers: z.object({ server: z.array(Listed).max(4096) }) })
        .parse(raw).servers.server;
    const found: Candidate[] = [];
    for (const row of rows) {
      const value = row.labels === undefined
        ? (await request("GET", `server/${row.uuid}`, undefined, true))?.server
        : row;
      if (value == null) continue;
      const detail = Listed.parse(value);
      if (detail.uuid !== row.uuid || detail.title !== row.title) {
        throw new SweepRefused();
      }
      const tag = labelValue(detail.labels, label);
      if (tag === null) throw new SweepRefused();
      found.push({ uuid: row.uuid, name: row.title, label, value: tag });
    }
    return found;
  };
  const task = await labelled("orbit-task-vm");
  const images = await labelled("orbit-task-image");
  const templates = z.object({
    storages: z.object({
      storage: z.array(
        z.object({ uuid: UUID, title: z.string(), access: z.string() }),
      ).max(4096),
    }),
  }).parse(await request("GET", "storage/template")).storages.storage;
  let snapshot = await check(); // Inventory first; current ownership second.
  let failed = false;
  const deleted: string[] = [];
  for (const candidate of [...task, ...images]) {
    if (!owned(candidate, snapshot, now())) continue;
    try {
      for (;;) {
        const server = await find(request, candidate);
        snapshot = await check(
          server !== null && ["maintenance", "error"].includes(server.state),
        );
        if (!owned(candidate, snapshot, now()) || server === null) break;
        if (server.state === "stopped") {
          await request(
            "DELETE",
            `server/${candidate.uuid}?storages=1&backups=delete`,
            undefined,
            true,
          );
          while (await find(request, candidate) !== null) {
            await check(true);
            await wait(signal);
          }
          deleted.push(candidate.name);
          break;
        }
        if (server.state === "started") {
          await request("POST", `server/${candidate.uuid}/stop`, {
            stop_server: { stop_type: "hard" },
          });
        }
        await wait(signal);
      }
    } catch (error) {
      if (!(error instanceof SweepRefused)) throw error;
      failed = true;
    }
  }
  for (const template of templates) {
    const match = /^orbit-task-base-([0-9]{14})-unverified$/.exec(
      template.title,
    );
    if (template.access !== "private" || match === null) continue;
    snapshot = await check();
    if (!stale(match[1], snapshot, now())) continue;
    try {
      const current = await request(
        "GET",
        `storage/${template.uuid}`,
        undefined,
        true,
      );
      if (current === null) continue;
      const value = z.object({
        uuid: UUID,
        title: z.string(),
        access: z.string(),
      }).parse(current.storage);
      if (
        value.uuid !== template.uuid || value.title !== template.title ||
        value.access !== "private"
      ) throw new SweepRefused();
      snapshot = await check();
      if (!stale(match[1], snapshot, now())) continue;
      await request("DELETE", `storage/${template.uuid}`, undefined, true);
      deleted.push(template.title);
    } catch (error) {
      if (!(error instanceof SweepRefused)) throw error;
      failed = true;
    }
  }
  const observed = (await labelled("orbit-task-vm")).map((row) => row.name);
  return {
    ...(failed
      ? { error_code: "task_vm.host_command_failed" as const }
      : { ready: true as const }),
    observed_names: [...new Set(observed)],
    deleted_names: [...new Set(deleted)],
  };
}
