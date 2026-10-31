/** Redis connection already authenticated and selected by the owning native effect. */
export interface SnapshotClient {
  command(args: string[]): Promise<unknown>;
}
export type SnapshotInput = {
  prefix: string;
  queue_prefix: string;
  trim_job: number;
  trim_queue: number;
  now?: number;
};

/** Publish before reset: a refused snapshot write must retain the measured counters. */
export const snapshotMetric = `
local data = redis.call('HMGET', KEYS[1], 'throughput', 'runtime')
local snapshot = {throughput = data[1] or cjson.null, runtime = data[2] or cjson.null, time = tonumber(ARGV[1])}
if ARGV[3] ~= '' then snapshot.wait = tonumber(ARGV[3]) end
redis.call('ZADD', KEYS[2], ARGV[1], cjson.encode(snapshot))
redis.call('ZREMRANGEBYRANK', KEYS[2], 0, -math.abs(1 + tonumber(ARGV[2])))
redis.call('DEL', KEYS[1])
return 1
`;

const unavailable = () => new Error("Horizon metrics could not be inspected.");
function name(value: unknown): string {
  if (
    typeof value !== "string" || !value.length || value.length > 1024 ||
    Array.from(value).some((c) =>
      c.charCodeAt(0) < 32 || c.charCodeAt(0) === 127
    )
  ) throw unavailable();
  return value;
}
function list(value: unknown): unknown[] {
  if (!Array.isArray(value) || value.length > 10000) throw unavailable();
  return value;
}
function object(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    throw unavailable();
  }
  return value as Record<string, unknown>;
}
function nonnegative(value: unknown): number {
  if (
    (typeof value !== "string" && typeof value !== "number") || value === "" ||
    !Number.isFinite(Number(value)) || Number(value) < 0
  ) throw unavailable();
  return Number(value);
}
async function measured(
  client: SnapshotClient,
  prefix: string,
  family: "job" | "queue",
): Promise<string[]> {
  return list(
    await client.command([
      "SMEMBERS",
      `${prefix}measured_${family === "job" ? "jobs" : "queues"}`,
    ]),
  ).map((value) => {
    const raw = name(value);
    return name(new RegExp(`${family}:(.*)$`).exec(raw)?.[1] ?? raw);
  }).sort();
}
async function processes(
  client: SnapshotClient,
  prefix: string,
  now: number,
): Promise<Map<string, number>> {
  const supervisors = list(
    await client.command([
      "ZREVRANGEBYSCORE",
      `${prefix}supervisors`,
      "+inf",
      String(now - 29),
    ]),
  ).map(name);
  const totals = new Map<string, number>();
  for (const supervisor of supervisors) {
    const record = list(
      await client.command([
        "HMGET",
        `${prefix}supervisor:${supervisor}`,
        "name",
        "master",
        "pid",
        "status",
        "processes",
        "options",
      ]),
    );
    if (record.length !== 6) throw unavailable();
    // Horizon drops expired supervisor hashes, including a record that expired after names().
    if (record[0] === null || record[0] === "") continue;
    name(record[0]);
    if (typeof record[4] !== "string" || record[4].length > 1024 * 1024) {
      throw unavailable();
    }
    let decoded: unknown;
    try {
      decoded = JSON.parse(record[4]);
    } catch {
      throw unavailable();
    }
    const counts = object(decoded);
    for (const [queue, value] of Object.entries(counts)) {
      name(queue);
      if (typeof value !== "number") throw unavailable();
      const count = nonnegative(value);
      if (
        !Number.isSafeInteger(count) ||
        !Number.isSafeInteger(count + (totals.get(queue) ?? 0))
      ) throw unavailable();
      totals.set(queue, count + (totals.get(queue) ?? 0));
    }
  }
  return totals;
}
async function queueWait(
  client: SnapshotClient,
  input: SnapshotInput,
  queue: string,
  totals: Map<string, number>,
): Promise<number> {
  // WaitTimeCalculator intersects the requested queue with the exact supervisor process keys.
  if (!totals.has(queue)) return 0;
  const colon = queue.indexOf(":");
  if (colon < 1 || queue.slice(0, colon) !== "gateway") throw unavailable();
  const queues = queue.slice(colon + 1).split(",").map(name);
  let milliseconds = 0;
  for (const item of queues) {
    const size = nonnegative(
      await client.command(["LLEN", `${input.queue_prefix}queues:${item}`]),
    );
    if (!Number.isSafeInteger(size)) throw unavailable();
    const runtime = await client.command([
      "HGET",
      `${input.prefix}queue:${item}`,
      "runtime",
    ]);
    milliseconds += size * (runtime === null ? 0 : nonnegative(runtime));
    if (!Number.isFinite(milliseconds)) throw unavailable();
  }
  const wait = Math.round(milliseconds / (totals.get(queue) || 1) / 1000);
  if (!Number.isSafeInteger(wait)) throw unavailable();
  return wait;
}

/** Native Horizon snapshot; native schedule concurrency owns overlap. */
export async function snapshot(
  client: SnapshotClient,
  input: SnapshotInput,
): Promise<{ jobs: number; queues: number }> {
  name(input.prefix);
  if (input.queue_prefix !== "") name(input.queue_prefix);
  const now = input.now ?? Math.floor(Date.now() / 1000);
  if (
    !Number.isSafeInteger(now) || now < 0 ||
    ![input.trim_job, input.trim_queue].every((v) =>
      Number.isSafeInteger(v) && Math.abs(v) <= 1000000
    )
  ) throw unavailable();
  const jobs = await measured(client, input.prefix, "job"),
    queues = await measured(client, input.prefix, "queue");
  const totals = queues.length
    ? await processes(client, input.prefix, now)
    : new Map<string, number>();
  const waits = new Map<string, number>();
  // Read every required queue before resets, so unsupported supervisor state cannot silently write wait=0.
  for (const queue of queues) {
    waits.set(queue, await queueWait(client, input, queue, totals));
  }
  for (
    const [family, values, retention] of [["job", jobs, input.trim_job], [
      "queue",
      queues,
      input.trim_queue,
    ]] as const
  ) {
    for (const value of values) {
      const key = `${input.prefix}${family}:${value}`;
      const stored = await client.command([
        "EVAL",
        snapshotMetric,
        "2",
        key,
        `${input.prefix}snapshot:${family}:${value}`,
        String(now),
        String(retention),
        family === "queue" ? String(waits.get(value)) : "",
      ]);
      if (stored !== 1) throw unavailable();
    }
  }
  if (
    await client.command([
      "SET",
      `${input.prefix}last_snapshot_at`,
      String(now),
    ]) !== "OK"
  ) throw unavailable();
  return { jobs: jobs.length, queues: queues.length };
}
