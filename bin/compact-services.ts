#!/usr/bin/env -S deno run --allow-read --allow-write
/** Receipt-aware initial compaction and bounded native runtime pruning. */
import { parse } from "jsr:@std/yaml@1.0.12";
import { dirname, join, resolve, relative } from "jsr:@std/path@1.1.4";
import { DatabaseSync } from "node:sqlite";

const DAY = 86400000;
const terminal = new Set(["succeeded", "failed", "cancelled"]);
const runtimeTypes = new Set(["nckrtl/orbit-group-run", "nckrtl/orbit-group-runs", "nckrtl/orbit-gateway", "nckrtl/orbit-driver", "nckrtl/runtime-retention", "workflow"]);
type Row = { id: string; type_normalized: string; model_id: string; data_name: string; version: number; is_latest: number; created_at: string; spec_name: string; tags: string; content_type: string; is_pending: number; lifecycle: string; snapshot_latest?: number };
type RecordFile = { path: string; value: Record<string, unknown> };
const normalized = (s: string) => s.replace(/^@/, "");
const decode = (s: string) => JSON.parse(s);
const workItemOf = (v:Record<string,unknown>) => String(v.workItem ?? (v.inputs as Record<string,unknown>|undefined)?.workItem ??
  (v.group ? `tg-${v.group}` : /^(tg-[1-9]\d*)[.-]/.exec(String(v.key))?.[1]) ?? "");
function refs(value: unknown, ids: Set<string>, versions: Set<string>) {
  if (typeof value === "string" && /^[a-f0-9-]{36}$/.test(value)) ids.add(value);
  if (!value || typeof value !== "object") return;
  const row = value as Record<string, unknown>;
  if (Number.isSafeInteger(row.version)) {
    if (typeof row.dataId === "string") versions.add(`${row.dataId}/${row.version}`);
    if (typeof (row.dataName ?? row.name) === "string") versions.add(`${row.dataName ?? row.name}/${row.version}`);
  }
  for (const item of Object.values(row)) refs(item, ids, versions);
}
async function records(dir: string): Promise<RecordFile[]> {
  const result: RecordFile[] = [];
  try {
    for await (const entry of Deno.readDir(dir)) {
      if (entry.isSymlink) throw new Error(`symlink_refused: ${dir}/${entry.name}`);
      const path = join(dir, entry.name);
      if (entry.isDirectory) result.push(...await records(path));
      else if (entry.name.endsWith(".yaml")) {
        const value = parse(await Deno.readTextFile(path));
        if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error(`invalid_record: ${path}`);
        result.push({ path, value: value as Record<string, unknown> });
      }
    }
  } catch (e) { if (!(e instanceof Deno.errors.NotFound)) throw e; }
  return result;
}
export async function assertOffline(repo: string) {
  for await (const entry of Deno.readDir("/proc")) {
    if (!/^\d+$/.test(entry.name) || Number(entry.name) === Deno.pid) continue;
    try {
      const cwd = await Deno.readLink(`/proc/${entry.name}/cwd`);
      const command = await Deno.readTextFile(`/proc/${entry.name}/cmdline`);
      const argv = command.split("\0");
      const explicitRepo = argv.some((a,i) => (a === "--repo-dir" && resolve(cwd,argv[i+1] ?? "") === repo) || a === `--repo-dir=${repo}`);
      if ((cwd === repo || explicitRepo) && /(?:^|\0|\/)swamp(?:\0|$)/.test(command)) throw new Error("swamp_process_active: stop serve and all writers first");
    } catch (e) { if (!(e instanceof Deno.errors.NotFound) && !(e instanceof Deno.errors.PermissionDenied)) throw e; }
  }
}
export type NativePrune = {
  deleteVersion: (row: Row) => Promise<void>;
  retire: (value: Record<string,unknown>) => Promise<void>;
  limit: number;
};
export async function compact(repo: string, apply = false, now = Date.now(), native?: NativePrune) {
  repo = await Deno.realPath(resolve(repo));
  if (native) return await compactUnlocked(repo,apply,now,native);
  await assertOffline(repo);
  const lockPath=join(repo,".swamp/.datastore.lock");
  const lock=await Deno.open(lockPath,{createNew:true,write:true,mode:0o600});
  try {
    await lock.write(new TextEncoder().encode(JSON.stringify({holder:"runtime-compactor",hostname:(await Deno.readTextFile("/proc/sys/kernel/hostname")).trim(),pid:Deno.pid,acquiredAt:new Date().toISOString(),ttlMs:86400000,nonce:crypto.randomUUID()})));
    return await compactUnlocked(repo,apply,now);
  } finally {lock.close();await Deno.remove(lockPath);}
}
async function compactUnlocked(repo: string, apply = false, now = Date.now(), native?: NativePrune) {
  repo = resolve(repo);
  if (!native) await assertOffline(repo);
  const marker = parse(await Deno.readTextFile(join(repo, ".swamp.yaml"))) as Record<string, unknown>;
  if (marker.datastore || marker.datastores || marker.datastoreId || marker.defaultDatastore) throw new Error("custom_datastore_refused");
  const data = join(repo, ".swamp/data");
  if (await Deno.realPath(data) !== data) throw new Error("data_symlink_refused");
  const dbPath = join(data, "_catalog.db");
  const db = new DatabaseSync(dbPath, { readOnly: true });
  let rows: Row[];
  try {
    rows = db.prepare("SELECT id,type_normalized,model_id,data_name,version,is_latest,created_at,spec_name,tags,content_type,is_pending,lifecycle FROM catalog WHERE namespace = ''").all() as unknown as Row[];
  } finally { db.close(); }
  const pathFor = (r: Row) => join(data, r.type_normalized, r.model_id, r.data_name, String(r.version));
  const cache = new Map<string, unknown>();
  const raw = async (r: Row) => {
    const key = pathFor(r);
    if (!cache.has(key)) cache.set(key, decode(await Deno.readTextFile(join(key, "raw"))));
    return cache.get(key) as Record<string, unknown>;
  };
  const latest = rows.filter((r) => r.is_latest);
  const groups = new Map<string, { status: string; enteredAt: string }[]>();
  for (const r of latest.filter((r) => r.data_name.startsWith("state-tg-") && normalized(r.type_normalized) === "swamp/software-factory")) {
    const v = await raw(r);
    if (typeof v.workItem !== "string" || typeof v.status !== "string" || typeof v.enteredAt !== "string") throw new Error("invalid_factory_state");
    const states = groups.get(v.workItem) ?? [];
    states.push({status:v.status, enteredAt:v.enteredAt}); groups.set(v.workItem, states);
  }
  const finished = new Set([...groups].filter(([, states]) => states.every((s) => s.status === "terminal" && Date.parse(s.enteredAt) < now - 7 * DAY)).map(([id]) => id));
  for (const r of latest.filter((r) => runtimeTypes.has(normalized(r.type_normalized)) && r.content_type === "application/json" && !r.data_name.startsWith("report-"))) {
    const v = await raw(r), workItem = workItemOf(v);
    if (v.completed === false || v.released === false || v.revoked === false || v.state === "running" || v.pending === true || (r.spec_name === "assistance" && (v.key || v.groupRequest))) finished.delete(workItem);
  }
  const runs = await records(join(repo, ".swamp/workflow-runs"));
  const pins = new Set<string>(), versions = new Set<string>();
  // All current durable receipts remain. Only proven old terminal groups cease
  // pinning their finished runtime history; their factory evidence is untouched.
  const receipts = latest.filter((r) => runtimeTypes.has(normalized(r.type_normalized)) &&
    r.content_type === "application/json" && !r.data_name.startsWith("report-") && !["tick", "result", "admission", "reconcile", "groups", "activity"].includes(r.spec_name));
  const factoryRoots = latest.filter((r) => normalized(r.type_normalized) === "swamp/software-factory" && r.content_type === "application/json" && /^(state|evidence|artifact|approval|dispatch)-/.test(r.data_name));
  for (const r of [...receipts,...factoryRoots]) {
    const v = await raw(r);
    const workItem = workItemOf(v);
    if (!finished.has(workItem)) refs(v, pins, versions);
  }
  const evaluated = await records(join(repo, ".swamp/workflows-evaluated"));
  const snapshots = evaluated.filter((r)=>r.path.includes("/runs/"));
  const live = runs.filter((r) => !terminal.has(String(r.value.status)));
  for (const r of live) refs(r.value, pins, versions);
  // A receipt can name a completed child; preserve its own outputs and children.
  const seen = new Set<string>(), seenData = new Set<string>();
  for (let changed = true; changed;) {
    changed = false;
    for (const r of rows) {
      const identity = `${r.id}/${r.version}`;
      if ((runtimeTypes.has(normalized(r.type_normalized)) || normalized(r.type_normalized) === "swamp/software-factory") && r.content_type === "application/json" && !seenData.has(identity) &&
          (versions.has(identity) || versions.has(`${r.data_name}/${r.version}`))) {
        seenData.add(identity); refs(await raw(r), pins, versions); changed = true;
      }
    }
    for (const r of runs) {
      const id = String(r.value.id);
      if (pins.has(id) && !seen.has(id)) {
        seen.add(id); refs(r.value, pins, versions);
        for (const snapshot of snapshots.filter((s) => s.path.includes(`/runs/${id}/`))) refs(snapshot.value,pins,versions);
        changed = true;
      }
    }
  }
  const outputs = await records(join(repo,".swamp/outputs"));
  const activeModels = new Set(outputs.filter((r)=>!terminal.has(String(r.value.status))).map((r)=>String(r.value.definitionId)));
  for (const run of live) {
    const definition = (run.value.inputs as Record<string,unknown> | undefined)?.definition;
    for (const row of latest) if (definition && decode(row.tags).modelName === definition) activeModels.add(row.model_id);
  }
  const latestVersions = new Map(latest.map((r)=>[`${r.model_id}/${r.data_name}`,r.version]));
  const remove: string[] = [];
  const protectedVersion = (r: Row) => versions.has(`${r.id}/${r.version}`) || versions.has(`${r.data_name}/${r.version}`);
  for (const r of rows) {
    if (!runtimeTypes.has(normalized(r.type_normalized)) || r.data_name.startsWith("dependencies-v1-") || r.is_pending || r.lifecycle === "deleted") continue;
    if (protectedVersion(r) || (native && activeModels.has(r.model_id))) continue;
    // A writer already evaluating inputs can still be publishing references.
    // Keep one hour online; the initial stopped-server pass compacts immediately.
    if (native && Date.parse(r.created_at) > now - 3600000) continue;
    const isOldReport = r.data_name.startsWith("report-") && Date.parse(r.created_at) < now - 7 * DAY;
    if (!r.is_latest || isOldReport) remove.push(pathFor(r));
  }
  const deletedRuns = new Set<string>();
  for (const r of runs) {
    if (terminal.has(String(r.value.status)) && !pins.has(String(r.value.id)) && Date.parse(String(r.value.completedAt ?? r.value.startedAt)) < now - 7 * DAY) {
      remove.push(r.path); deletedRuns.add(String(r.value.id));
      for (const log of [r.path.replace(/\.yaml$/, ".log"),join(repo,".swamp/logs",String(r.value.workflowId),String(r.value.id)+".log")]) {
        try { await Deno.lstat(log); remove.push(log); } catch(e) { if (!(e instanceof Deno.errors.NotFound)) throw e; }
      }
    }
  }
  for (const r of evaluated) {
    const runId = String(r.value.runId ?? r.value.workflowRunId ?? r.path.split("/runs/")[1]?.split("/")[0] ?? "");
    const orphan = runId && !runs.some((run) => run.value.id === runId) && (await Deno.stat(r.path)).mtime!.getTime() < now - 7 * DAY;
    if (deletedRuns.has(runId) || orphan) remove.push(r.path);
  }
  for (const r of outputs) {
    if (terminal.has(String(r.value.status)) && !pins.has(String(r.value.id)) && Date.parse(String(r.value.completedAt ?? r.value.startedAt)) < now - 7 * DAY && ![...pins].some((id) => JSON.stringify(r.value.provenance ?? {}).includes(id))) {
      remove.push(r.path);
      const recorded = typeof r.value.logFile === "string" ? resolve(repo,r.value.logFile) : r.path.replace(/\.yaml$/, ".log");
      if (dirname(recorded) === dirname(r.path) && recorded.endsWith(".log")) {
        try { await Deno.lstat(recorded); remove.push(recorded); } catch(e) { if (!(e instanceof Deno.errors.NotFound)) throw e; }
      }
    }
  }
  const retire = (await records(join(repo, ".swamp/auto-definitions"))).filter((r) => {
    const name = String(r.value.name);
    const workItem = /^orbit-group-(?:recovery-)?(tg-[1-9]\d*)$/.exec(name)?.[1];
    return normalized(String(r.value.type)) === "nckrtl/orbit-group-run" && workItem && finished.has(workItem) &&
      !pins.has(String(r.value.id)) && !live.some((run) => JSON.stringify(run.value).includes(name));
  });
  // Refuse unsafe or unexpected paths before any mutation.
  for (const path of [...remove, ...retire.map((r) => r.path)]) {
    if (relative(repo, path).startsWith("..") || await Deno.realPath(path) !== path) throw new Error("unsafe_compaction_path");
  }
  const plan = { apply, versions: remove.filter((p) => p.startsWith(data + "/")).length, runs: deletedRuns.size, paths: remove.map((p) => relative(repo, p)), more: !!native && remove.length > native.limit, protectedRuns: seen.size, retiredModels: retire.map((r) => r.value.name) };
  if (apply) {
    if (!native) await assertOffline(repo);
    await Deno.writeTextFile(join(repo, ".swamp/compaction-plan.json"), JSON.stringify(plan));
    // Catalog is a derived read model. Invalidate BEFORE deletes, including on
    // crash, so the next native query rebuilds from surviving metadata.
    if (!native) for (const suffix of ["", "-wal", "-shm", "-journal"]) {
      await Deno.remove(dbPath + suffix).catch((e) => { if (!(e instanceof Deno.errors.NotFound)) throw e; });
    }
    const deleting = new Set(remove);
    // Preserve each name's high-water mark when its latest version is removed.
    if (!native) for (const r of latest.filter((r) => deleting.has(pathFor(r)))) {
      const dir = dirname(pathFor(r));
      const floorPath = dir + "..version-floor";
      const oldFloor = await Deno.readTextFile(floorPath).catch((e) => { if (e instanceof Deno.errors.NotFound) return "0"; throw e; });
      await Deno.writeTextFile(floorPath, String(Math.max(Number(oldFloor),r.version)) + "\n");
      const kept = rows.filter((v) => v.model_id === r.model_id && v.data_name === r.data_name && !deleting.has(pathFor(v)));
      await Deno.writeTextFile(join(dir,"latest"),String(Math.max(0,...kept.map((v) => v.version))) + "\n");
    }
    for (const r of retire) {
      const target = join(repo,".swamp/retired-group-definitions",String(r.value.id)+".yaml");
      await Deno.mkdir(dirname(target),{recursive:true});
      if (native) {
        await Deno.copyFile(r.path,target);
        await native.retire(r.value);
      } else await Deno.rename(r.path,target);
    }
    const byPath = new Map(rows.map((r) => [pathFor(r),r]));
    for (const path of remove.slice(0,native?.limit ?? remove.length)) {
      const row = byPath.get(path);
      if (native && row) await native.deleteVersion({...row,snapshot_latest:latestVersions.get(`${row.model_id}/${row.data_name}`)});
      else await Deno.remove(path, { recursive: true });
    }
    for (const dir of new Set(runs.filter((r) => deletedRuns.has(String(r.value.id))).map((r) => dirname(r.path)))) {
      await Deno.remove(join(dir,".runs-index.json")).catch((e) => { if (!(e instanceof Deno.errors.NotFound)) throw e; });
    }
  }
  return plan;
}
if (import.meta.main) {
  const args = Deno.args;
  const repo = args.find((a) => a.startsWith("--repo="))?.slice(7) ?? Deno.cwd();
  if (args.some((a) => a !== "--apply" && !a.startsWith("--repo="))) throw new Error("usage: compact-services.ts [--repo=PATH] [--apply]");
  console.log(JSON.stringify(await compact(repo, args.includes("--apply"))));
}
