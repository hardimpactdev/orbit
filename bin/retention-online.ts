import { assertRetentionTarget } from "./retention-paths.ts";
/** Bounded, resumable receipt graph and native pruning for the filesystem store. */
import { DatabaseSync } from "node:sqlite";
import { join, resolve } from "jsr:@std/path@1.1.4";
import { parse } from "jsr:@std/yaml@1.0.12";

type Row = {
  tags: string;
  id: string;
  type_normalized: string;
  model_id: string;
  data_name: string;
  version: number;
  is_latest: number;
  created_at: string;
  spec_name: string;
  content_type: string;
  is_pending: number;
  lifecycle: string;
};
type Node = {
  key: string;
  path: string;
  kind: string;
  stamp: string;
  value: string;
  root: number;
  ref: string;
  model: string;
  ended: string;
};
export type OnlinePrune = {
  limit: number;
  budgetMs?: number;
  clock?: () => number;
  deleteVersion: (row: Row & { snapshot_latest: number }) => Promise<boolean>;
};
const types = [
  "nckrtl/orbit-group-run",
  "nckrtl/orbit-group-runs",
  "nckrtl/orbit-gateway",
  "nckrtl/orbit-driver",
  "nckrtl/runtime-retention",
  "workflow",
];
const terminal = new Set(["succeeded", "failed", "cancelled"]);
const stateTypes = [...types, "swamp/software-factory"];
const DAY = 86400000;
const stamp = (s: Deno.FileInfo) =>
  JSON.stringify([s.ino, s.size, s.mtime?.getTime(), s.ctime?.getTime()]);
const fingerprint = (s: Deno.FileInfo) => stamp(s);
function references(value: unknown, out = new Set<string>()): Set<string> {
  if (typeof value === "string" && /^[a-f0-9-]{36}$/.test(value)) {
    out.add(value);
  }
  if (!value || typeof value !== "object") return out;
  const v = value as Record<string, unknown>;
  if (Number.isSafeInteger(v.version)) {
    if (typeof v.dataId === "string") out.add(`${v.dataId}/${v.version}`);
    if (typeof (v.dataName ?? v.name) === "string") {
      out.add(`${v.dataName ?? v.name}/${v.version}`);
    }
  }
  for (const item of Object.values(v)) references(item, out);
  return out;
}
type DirectoryEntry = { name: string; is_directory: number; stamp: string };
async function directorySnapshot(path: string) {
  const entries: DirectoryEntry[] = [];
  let count = 0;
  for await (const entry of Deno.readDir(path)) {
    if (++count > 250_000) throw new Error("retention_directory_too_large");
    if (entry.isSymlink) throw new Error("symlink_refused");
    if (entry.isDirectory) {
      entries.push({ name: entry.name, is_directory: 1, stamp: "" });
    } else if (entry.name.endsWith(".yaml")) {
      try {
        const stat = await Deno.lstat(join(path, entry.name));
        if (!stat.isFile) throw new Error("unsafe_retention_source");
        entries.push({
          name: entry.name,
          is_directory: 0,
          stamp: fingerprint(stat),
        });
      } catch (e) {
        if (!(e instanceof Deno.errors.NotFound)) throw e;
      }
    }
  }
  entries.sort((a, b) => a.name < b.name ? -1 : a.name > b.name ? 1 : 0);
  const hash = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(JSON.stringify(entries)),
  );
  return {
    entries,
    signature: Array.from(
      new Uint8Array(hash),
      (b) => b.toString(16).padStart(2, "0"),
    ).join(""),
  };
}
export async function pruneOnline(
  repo: string,
  native: OnlinePrune,
  now = Date.now(),
) {
  repo = await Deno.realPath(resolve(repo));
  const marker = parse(
    await Deno.readTextFile(join(repo, ".swamp.yaml")),
  ) as Record<string, unknown>;
  if (
    marker.datastore || marker.datastores || marker.datastoreId ||
    marker.defaultDatastore
  ) throw new Error("custom_datastore_refused");
  const base = join(repo, ".swamp"),
    data = join(base, "data"),
    cache = join(base, "retention-planner.db");
  if (await Deno.realPath(data) !== data) {
    throw new Error("data_symlink_refused");
  }
  try {
    if (!(await Deno.lstat(cache)).isFile) {
      throw new Error("unsafe_retention_cache");
    }
  } catch (e) {
    if (!(e instanceof Deno.errors.NotFound)) throw e;
    (await Deno.open(cache, { createNew: true, write: true, mode: 0o600 }))
      .close();
  }
  await Deno.chmod(cache, 0o600);
  const db = new DatabaseSync(cache),
    catalog = new DatabaseSync(join(data, "_catalog.db"), { readOnly: true });
  db.exec(`PRAGMA journal_mode=WAL;
 CREATE TABLE IF NOT EXISTS state(key TEXT PRIMARY KEY,value TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS dirs(path TEXT PRIMARY KEY,stamp TEXT NOT NULL DEFAULT '',cursor TEXT NOT NULL DEFAULT '',done INTEGER NOT NULL DEFAULT 0);
 CREATE TABLE IF NOT EXISTS directory_fingerprints(path TEXT PRIMARY KEY,signature TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS directory_scans(path TEXT PRIMARY KEY);
 CREATE TABLE IF NOT EXISTS directory_entries(path TEXT NOT NULL,name TEXT NOT NULL,is_directory INTEGER NOT NULL,PRIMARY KEY(path,name));
 CREATE TABLE IF NOT EXISTS files(path TEXT PRIMARY KEY,kind TEXT NOT NULL,pending INTEGER NOT NULL DEFAULT 1);
 CREATE TABLE IF NOT EXISTS nodes(key TEXT PRIMARY KEY,path TEXT NOT NULL,kind TEXT NOT NULL,stamp TEXT NOT NULL DEFAULT '',value TEXT NOT NULL DEFAULT '',root INTEGER NOT NULL DEFAULT 0,ref TEXT NOT NULL DEFAULT '',model TEXT NOT NULL DEFAULT '',ended TEXT NOT NULL DEFAULT '');
 CREATE TABLE IF NOT EXISTS catalog_references(ref TEXT NOT NULL,key TEXT NOT NULL,ordinal INTEGER NOT NULL,scoped INTEGER NOT NULL,PRIMARY KEY(ref,key));
 CREATE TABLE IF NOT EXISTS aliases(ref TEXT NOT NULL,node TEXT NOT NULL,PRIMARY KEY(ref,node));
 CREATE TABLE IF NOT EXISTS edges(src TEXT NOT NULL,dst TEXT NOT NULL,PRIMARY KEY(src,dst));
 CREATE TABLE IF NOT EXISTS pins(ref TEXT PRIMARY KEY,done INTEGER NOT NULL DEFAULT 0,cursor TEXT NOT NULL DEFAULT '');
 CREATE INDEX IF NOT EXISTS node_path ON nodes(path);CREATE INDEX IF NOT EXISTS file_pending ON files(pending,path);
 CREATE TABLE IF NOT EXISTS trash(path TEXT PRIMARY KEY,owner TEXT NOT NULL);
 CREATE INDEX IF NOT EXISTS node_roots ON nodes(root);CREATE INDEX IF NOT EXISTS pins_pending ON pins(done);`);
  const get = (key: string, fallback = "") =>
    String(
      db.prepare("SELECT value FROM state WHERE key=?").get(key)?.value ??
        fallback,
    );
  const set = (key: string, value: unknown) =>
    db.prepare("INSERT OR REPLACE INTO state VALUES (?,?)").run(
      key,
      String(value),
    );
  const clock = native.clock ?? (() => performance.now()),
    end = clock() + (native.budgetMs ?? 80_000);
  let steps = 0, removedVersions = 0, removedPaths = 0;
  const report = () => ({
    partial: get("phase") !== "done",
    phase: get("phase"),
    scanned: steps,
    removedVersions,
    removedPaths,
    more: get("phase") !== "done",
  });
  const source = (r: Row) =>
    join(
      data,
      r.type_normalized,
      r.model_id,
      r.data_name,
      String(r.version),
      "raw",
    );
  const alias = (ref: string, key: string) => {
    const added = db.prepare("INSERT OR IGNORE INTO aliases VALUES (?,?)").run(
      ref,
      key,
    );
    if (added.changes) {
      db.prepare("UPDATE pins SET done=0,cursor='' WHERE ref=?").run(ref);
    }
  };
  const pin = (ref: string) =>
    db.prepare("INSERT OR IGNORE INTO pins(ref,done) VALUES (?,0)").run(ref);
  const edge = (src: string, dst: string) =>
    db.prepare("INSERT OR IGNORE INTO edges VALUES (?,?)").run(src, dst);
  const normalize = (s: string) => s.replace(/^@/, "");
  const forget = (key: string) => {
    db.prepare(
      "DELETE FROM files WHERE path IN (SELECT path FROM nodes WHERE key=?)",
    ).run(key);
    db.prepare("DELETE FROM edges WHERE src=? OR dst=?").run(key, key);
    db.prepare("DELETE FROM aliases WHERE node=?").run(key);
    db.prepare("DELETE FROM nodes WHERE key=?").run(key);
  };
  async function readNode(n: Node) {
    let s: Deno.FileInfo;
    try {
      s = await Deno.lstat(n.path);
    } catch (e) {
      if (e instanceof Deno.errors.NotFound) {
        forget(n.key);
        return;
      }
      throw e;
    }
    if (!s.isFile || await Deno.realPath(n.path) !== n.path) {
      throw new Error("unsafe_retention_source");
    }
    if (s.size > 16 * 1024 * 1024) {
      throw new Error("retention_record_too_large");
    }
    const fresh = fingerprint(s);
    if (n.stamp === fresh && n.value) return;
    const text = await Deno.readTextFile(n.path),
      value = n.kind === "data" ? JSON.parse(text) : parse(text);
    if (!value || typeof value !== "object" || Array.isArray(value)) {
      throw new Error("invalid_retention_record");
    }
    const v = value as Record<string, unknown>;
    db.prepare("DELETE FROM edges WHERE src=?").run(n.key);
    for (const ref of references(v)) edge(n.key, ref);
    let root = n.root;
    if (n.kind === "run" || n.kind === "output") {
      root = terminal.has(String(v.status)) ? 0 : 1;
    }
    const ref = n.kind === "run" || n.kind === "output" ? String(v.id) : n.ref;
    if (n.kind === "run" && !/^[a-f0-9-]{36}$/.test(ref)) {
      throw new Error("invalid_retention_run");
    }
    if (ref) alias(ref, n.key);
    if (n.kind === "snapshot") {
      const run = /\/runs\/([a-f0-9-]{36})\//.exec(n.path)?.[1];
      if (run) edge(run, n.key);
    }
    db.prepare(
      "UPDATE nodes SET stamp=?,value=?,root=?,ref=?,model=?,ended=? WHERE key=?",
    )
      .run(
        fresh,
        JSON.stringify({
          workflowId: v.workflowId,
          logFile: v.logFile,
          inputs: {
            definition: (v.inputs as Record<string, unknown> | undefined)
              ?.definition,
          },
        }),
        root,
        ref,
        String(v.definitionId ?? ""),
        String(v.completedAt ?? v.startedAt ?? ""),
        n.key,
      );
    // Requeue already-reached data when its authoritative source changed.
    db.prepare("UPDATE pins SET done=0,cursor='' WHERE ref=?").run(n.key);
  }
  function addFile(path: string, kind: string) {
    db.prepare(
      "INSERT INTO files VALUES (?,?,1) ON CONFLICT(path) DO UPDATE SET pending=1",
    ).run(path, kind);
    db.prepare("INSERT OR IGNORE INTO nodes(key,path,kind) VALUES (?,?,?)").run(
      path,
      path,
      kind,
    );
  }
  const roots = [
    "workflow-runs",
    "workflows-evaluated",
    "outputs",
    "auto-definitions",
  ];
  function begin() {
    // Parsed source cache survives generations. Reachability and candidates do not.
    db.exec(
      "DELETE FROM directory_entries;DELETE FROM directory_scans;DELETE FROM pins;UPDATE nodes SET root=0 WHERE kind='data';UPDATE dirs SET done=0,cursor='';UPDATE files SET pending=1;",
    );
    for (const root of roots) {
      db.prepare("INSERT OR IGNORE INTO dirs(path) VALUES (?)").run(
        join(base, root),
      );
    }
    set("catalog", "0");
    set("candidate", "0");
    set("history", "");
    set("root", "");
    set("validate-root", "0");
    set("resume", "prune");
    set("phase", "inventory");
  }
  if (!get("phase") || get("phase") === "done") begin();
  else if (["prune", "history", "cleanup"].includes(get("phase"))) {
    set("validate-root", "0");
    set("resume", get("phase"));
    set("phase", "validate");
    set("validate", "");
  }
  if (!get("catalog-reference-schema")) {
    // Backfill locators while keeping parsed files, graph and deletion cursors.
    db.exec("BEGIN");
    set("catalog-reference-schema", "1");
    set("catalog", "0");
    set("phase", "inventory");
    db.exec("COMMIT");
  }
  const catalogAdvanced = () =>
    Number(
      catalog.prepare(
        "SELECT COALESCE(MAX(rowid),0) AS n FROM catalog WHERE namespace=''",
      ).get()!.n,
    ) > Number(get("catalog"));
  try {
    while (clock() < end) {
      steps++;
      const phase = get("phase");
      const transaction = ["inventory", "directories", "files", "graph"]
        .includes(phase);
      if (transaction) db.exec("BEGIN");
      let failed = false;
      try {
        if (phase === "inventory") {
          const rows = catalog.prepare(
            "SELECT rowid AS ordinal,* FROM catalog WHERE namespace='' AND rowid>? ORDER BY rowid LIMIT 100",
          ).all(
            Number(get("catalog", "0")),
          ) as unknown as (Row & { ordinal: number })[];
          if (!rows.length) {
            set("phase", "directories");
            continue;
          }
          for (const r of rows) {
            const scoped = Number(
              stateTypes.includes(normalize(r.type_normalized)) &&
                r.content_type === "application/json",
            );
            const key = `${r.id}/${r.version}`;
            for (const ref of [key, `${r.data_name}/${r.version}`]) {
              db.prepare(
                "INSERT INTO catalog_references VALUES (?,?,?,?) ON CONFLICT(ref,key) DO UPDATE SET ordinal=excluded.ordinal,scoped=excluded.scoped",
              ).run(ref, key, r.ordinal, scoped);
            }
            if (
              stateTypes.includes(normalize(r.type_normalized)) &&
              r.content_type === "application/json" && !r.is_pending &&
              r.lifecycle !== "deleted"
            ) {
              const key = `${r.id}/${r.version}`, path = source(r);
              const root = !!r.is_latest &&
                !r.data_name.startsWith("report-") &&
                ![
                  "tick",
                  "result",
                  "admission",
                  "reconcile",
                  "groups",
                  "activity",
                ].includes(r.spec_name);
              db.prepare(
                "INSERT INTO nodes(key,path,kind,root) VALUES (?,?,'data',?) ON CONFLICT(key) DO UPDATE SET root=excluded.root",
              ).run(key, path, Number(root));
              alias(key, key);
              alias(`${r.data_name}/${r.version}`, key);
            }
          }
          set("catalog", rows.at(-1)!.ordinal);
        } else if (phase === "directories") {
          const dir = db.prepare(
            "SELECT * FROM dirs WHERE done=0 ORDER BY path LIMIT 1",
          ).get() as
            | { path: string; stamp: string; cursor: string }
            | undefined;
          if (!dir) {
            set("phase", "files");
            continue;
          }
          let stat: Deno.FileInfo;
          try {
            stat = await Deno.lstat(dir.path);
          } catch (e) {
            if (e instanceof Deno.errors.NotFound) {
              db.prepare("DELETE FROM directory_entries WHERE path=?").run(
                dir.path,
              );
              db.prepare("DELETE FROM directory_scans WHERE path=?").run(
                dir.path,
              );
              db.prepare("DELETE FROM dirs WHERE path=?").run(dir.path);
              continue;
            }
            throw e;
          }
          if (!stat.isDirectory || await Deno.realPath(dir.path) !== dir.path) {
            throw new Error("unsafe_retention_directory");
          }
          // Snapshot a complete listing once. Directory writes must not rewind
          // an in-flight cursor, or a busy run/log directory never finishes.
          if (
            !db.prepare("SELECT 1 FROM directory_scans WHERE path=?").get(
              dir.path,
            )
          ) {
            const snapshot = await directorySnapshot(dir.path);
            const insert = db.prepare(
              "INSERT INTO directory_entries VALUES (?,?,?)",
            );
            for (const entry of snapshot.entries) {
              insert.run(dir.path, entry.name, entry.is_directory);
            }
            db.prepare(
              "INSERT OR REPLACE INTO directory_fingerprints VALUES (?,?)",
            ).run(dir.path, snapshot.signature);
            db.prepare("INSERT INTO directory_scans VALUES (?)").run(dir.path);
            db.prepare("UPDATE dirs SET cursor='',stamp=? WHERE path=?").run(
              stamp(stat),
              dir.path,
            );
            dir.cursor = "";
          }
          const batch = db.prepare(
            "SELECT name,is_directory FROM directory_entries WHERE path=? AND name>? ORDER BY name LIMIT 100",
          ).all(dir.path, dir.cursor) as unknown as {
            name: string;
            is_directory: number;
          }[];
          for (const entry of batch) {
            const path = join(dir.path, entry.name);
            if (entry.is_directory) {
              db.prepare("INSERT OR IGNORE INTO dirs(path) VALUES (?)").run(
                path,
              );
            } else {
              let current: Deno.FileInfo;
              try {
                current = await Deno.lstat(path);
              } catch (e) {
                if (e instanceof Deno.errors.NotFound) continue;
                throw e;
              }
              if (!current.isFile || await Deno.realPath(path) !== path) {
                throw new Error("unsafe_retention_source");
              }
              // A changed directory need not requeue every unchanged record.
              const cached = db.prepare("SELECT stamp FROM nodes WHERE key=?")
                .get(path);
              if (cached?.stamp === fingerprint(current)) continue;
              const root = roots.find((root) =>
                path.startsWith(join(base, root) + "/")
              )!;
              addFile(
                path,
                root === "workflow-runs"
                  ? "run"
                  : root === "outputs"
                  ? "output"
                  : root === "workflows-evaluated"
                  ? "snapshot"
                  : "definition",
              );
            }
          }
          db.prepare("UPDATE dirs SET cursor=?,done=? WHERE path=?").run(
            batch.at(-1)?.name ?? dir.cursor,
            Number(batch.length < 100),
            dir.path,
          );
          if (batch.length < 100) {
            db.prepare("DELETE FROM directory_entries WHERE path=?").run(
              dir.path,
            );
            db.prepare("DELETE FROM directory_scans WHERE path=?").run(
              dir.path,
            );
          }
        } else if (phase === "files") {
          const pending = db.prepare(
            "SELECT n.* FROM files f JOIN nodes n ON n.path=f.path WHERE f.pending=1 ORDER BY f.path LIMIT 1",
          ).get() as Node | undefined;
          if (pending) {
            await readNode(pending);
            db.prepare("UPDATE files SET pending=0 WHERE path=?").run(
              pending.path,
            );
            continue;
          }
          const root = db.prepare(
            "SELECT * FROM nodes WHERE root=1 AND kind='data' AND key>? ORDER BY key LIMIT 1",
          ).get(get("root", "")) as Node | undefined;
          if (root) {
            await readNode(root);
            set("root", root.key);
            continue;
          }
          db.prepare(
            "INSERT OR IGNORE INTO pins(ref,done) SELECT key,0 FROM nodes WHERE root=1",
          ).run();
          set("root", "");
          set("phase", "graph");
        } else if (phase === "graph") {
          const p = db.prepare(
            "SELECT ref,cursor FROM pins WHERE done=0 LIMIT 1",
          )
            .get() as { ref: string; cursor: string } | undefined;
          if (!p) {
            set("phase", "validate");
            set("validate", "");
            continue;
          }
          const version = /^(.*)\/([1-9]\d*)$/.exec(p.ref);
          const nodes: Node[] = [];
          let full: boolean, cursor: string;
          if (version) {
            // One cursor covers both cached aliases and unpublished locators.
            // A cached higher key must not hide a lower pending row promoted
            // in-place since inventory (no new native rowid).
            const reached = db.prepare(
              `SELECT k.key,c.ordinal,c.scoped FROM (
                SELECT key FROM catalog_references WHERE ref=? AND key>?
                UNION SELECT n.key FROM aliases a JOIN nodes n ON n.key=a.node WHERE a.ref=? AND n.key>?
                UNION SELECT key FROM nodes WHERE key=? AND key>?
              ) k LEFT JOIN catalog_references c ON c.ref=? AND c.key=k.key
              ORDER BY k.key LIMIT 100`,
            ).all(
              p.ref,
              p.cursor,
              p.ref,
              p.cursor,
              p.ref,
              p.cursor,
              p.ref,
            ) as unknown as {
              key: string;
              ordinal: number | null;
              scoped: number | null;
            }[];
            if (!reached.length && catalogAdvanced()) {
              set("phase", "inventory");
              continue;
            }
            full = reached.length === 100;
            cursor = reached.at(-1)?.key ?? p.cursor;
            for (const location of reached) {
              if (location.ordinal === null) {
                const cached = db.prepare("SELECT * FROM nodes WHERE key=?")
                  .get(location.key) as Node | undefined;
                if (cached) nodes.push(cached);
                continue;
              }
              if (!location.scoped) continue;
              // Recheck publication and identity; pending rows can be promoted
              // without adding a row. Never scan the native namespace per edge.
              const r = catalog.prepare(
                "SELECT * FROM catalog WHERE rowid=? AND namespace=''",
              ).get(location.ordinal) as Row | undefined;
              if (!r || `${r.id}/${r.version}` !== location.key) {
                // Native compaction can move rowids. Rebuild only the derived
                // locator inventory in batches; retain all reachability pins.
                db.exec("DELETE FROM catalog_references");
                set("catalog", "0");
                set("phase", "inventory");
                break;
              }
              if (
                !stateTypes.includes(normalize(r.type_normalized)) ||
                r.is_pending || r.content_type !== "application/json" ||
                r.lifecycle === "deleted"
              ) continue;
              const key = location.key;
              db.prepare(
                "INSERT OR IGNORE INTO nodes(key,path,kind) VALUES (?,?,'data')",
              ).run(key, source(r));
              alias(key, key);
              alias(`${r.data_name}/${r.version}`, key);
              nodes.push(
                db.prepare("SELECT * FROM nodes WHERE key=?").get(key) as Node,
              );
            }
          } else {
            nodes.push(
              ...db.prepare(
                "SELECT n.* FROM aliases a JOIN nodes n ON n.key=a.node WHERE a.ref=? AND n.key>? UNION SELECT * FROM nodes WHERE key=? AND key>? ORDER BY key LIMIT 100",
              ).all(p.ref, p.cursor, p.ref, p.cursor) as unknown as Node[],
            );
            full = nodes.length === 100;
            cursor = nodes.at(-1)?.key ?? p.cursor;
          }
          if (get("phase") === "inventory") continue;
          for (const n of nodes) {
            if (n.kind === "data") await readNode(n);
            for (
              const e of db.prepare("SELECT dst FROM edges WHERE src=?").all(
                n.key,
              )
            ) pin(String(e.dst));
          }
          // Snapshot links are attached to the run identity, including missing runs.
          for (
            const e of db.prepare("SELECT dst FROM edges WHERE src=?").all(
              p.ref,
            )
          ) pin(String(e.dst));
          db.prepare("UPDATE pins SET done=?,cursor=? WHERE ref=?").run(
            Number(!full),
            cursor,
            p.ref,
          );
        } else if (phase === "validate") {
          // Swamp atomically replaces run/output records. Parent directory stamps
          // expose changed/new records without reparsing the history each pass.
          const dir = db.prepare(
            "SELECT * FROM dirs WHERE path>? ORDER BY path LIMIT 1",
          ).get(get("validate")) as { path: string; stamp: string } | undefined;
          if (dir) {
            let current = "";
            try {
              current = stamp(await Deno.lstat(dir.path));
            } catch (e) {
              if (!(e instanceof Deno.errors.NotFound)) throw e;
            }
            if (current !== dir.stamp) {
              // Log creation changes the directory stamp but introduces no
              // reference. Compare YAML names and authoritative fingerprints;
              // only reference changes need another graph refresh.
              const saved = db.prepare(
                "SELECT signature FROM directory_fingerprints WHERE path=?",
              ).get(dir.path);
              if (
                current &&
                saved?.signature ===
                  (await directorySnapshot(dir.path)).signature
              ) {
                db.prepare("UPDATE dirs SET stamp=? WHERE path=?").run(
                  current,
                  dir.path,
                );
                set("validate", dir.path);
                continue;
              }
              db.prepare("UPDATE dirs SET done=0,cursor='' WHERE path=?").run(
                dir.path,
              );
              set("phase", "directories");
              continue;
            }
            set("validate", dir.path);
            continue;
          }
          // A promoted pending row may keep its rowid. Find missing latest
          // roots directly, rather than assuming every publication adds a row.
          const root = catalog.prepare(`SELECT rowid AS ordinal,* FROM catalog
            WHERE namespace='' AND is_latest=1 AND is_pending=0 AND lifecycle!='deleted'
            AND ltrim(type_normalized,'@') IN (${
            stateTypes.map(() => "?").join(",")
          })
            AND content_type='application/json' AND data_name NOT LIKE 'report-%'
            AND spec_name NOT IN ('tick','result','admission','reconcile','groups','activity')
            AND rowid>? ORDER BY rowid LIMIT 1`).get(
            ...stateTypes,
            Number(get("validate-root", "0")),
          ) as (Row & { ordinal: number }) | undefined;
          if (root) {
            set("validate-root", root.ordinal);
            const key = `${root.id}/${root.version}`;
            const existing = db.prepare("SELECT root FROM nodes WHERE key=?")
              .get(key);
            if (existing?.root !== 1) {
              db.prepare(
                "INSERT INTO nodes(key,path,kind,root) VALUES (?,?,'data',1) ON CONFLICT(key) DO UPDATE SET root=1",
              ).run(key, source(root));
              alias(key, key);
              alias(`${root.data_name}/${root.version}`, key);
              await readNode(
                db.prepare("SELECT * FROM nodes WHERE key=?").get(key) as Node,
              );
              pin(key);
              set("phase", "graph");
            }
            continue;
          }
          // Newly published receipt versions are roots too. A refresh is incremental.
          const changed = Number(
            catalog.prepare(
              "SELECT COALESCE(MAX(rowid),0) AS n FROM catalog WHERE namespace=''",
            ).get()!.n,
          ) > Number(get("catalog"));
          if (changed) {
            set("phase", "inventory");
            continue;
          }
          set("phase", get("resume", "prune"));
        } else if (phase === "prune") {
          const rows = catalog.prepare(
            "SELECT rowid AS ordinal,* FROM catalog WHERE namespace='' AND rowid>? ORDER BY rowid LIMIT 100",
          ).all(
            Number(get("candidate", "0")),
          ) as unknown as (Row & { ordinal: number })[];
          if (!rows.length) {
            set("phase", "history");
            continue;
          }
          for (const r of rows) {
            if (clock() >= end || removedPaths >= native.limit) break;
            const key = `${r.id}/${r.version}`;
            const protectedVersion = db.prepare(
              "SELECT 1 FROM pins WHERE ref IN (?,?) LIMIT 1",
            ).get(key, `${r.data_name}/${r.version}`);
            const name = JSON.parse(r.tags).modelName;
            const active = db.prepare(
              "SELECT 1 FROM nodes WHERE root=1 AND ((kind='output' AND model=?) OR (kind='run' AND json_extract(value,'$.inputs.definition')=?)) LIMIT 1",
            ).get(r.model_id, String(name));
            if (
              types.includes(normalize(r.type_normalized)) &&
              !r.data_name.startsWith("dependencies-v1-") && !r.is_pending &&
              r.lifecycle !== "deleted" && !protectedVersion && !active &&
              Date.parse(r.created_at) < now - 3600000 &&
              (!r.is_latest ||
                (r.data_name.startsWith("report-") &&
                  Date.parse(r.created_at) < now - 7 * DAY))
            ) {
              const latest = catalog.prepare(
                "SELECT version FROM catalog WHERE namespace='' AND type_normalized=? AND model_id=? AND data_name=? AND is_latest=1",
              ).get(r.type_normalized, r.model_id, r.data_name);
              assertRetentionTarget(repo,source(r));
              if (
                latest &&
                await native.deleteVersion({
                  ...r,
                  snapshot_latest: Number(latest.version),
                })
              ) {
                removedVersions++;
                removedPaths++;
                forget(key);
              }
            }
            set("candidate", r.ordinal);
          }
          if (removedPaths >= native.limit) break;
        } else if (phase === "history") {
          // Preserve all records for reached runs/outputs, including completed children.
          // Definitions and snapshots remain until their owner records are pruned.
          const n = db.prepare(
            "SELECT * FROM nodes WHERE kind IN ('run','output') AND key>? ORDER BY key LIMIT 1",
          ).get(get("history")) as Node | undefined;
          if (!n) {
            set("phase", "cleanup");
            continue;
          }
          const reached = db.prepare(
            "SELECT 1 FROM pins WHERE ref IN (?,?) LIMIT 1",
          ).get(n.key, n.ref);
          if (!n.root && !reached && Date.parse(n.ended) < now - 7 * DAY) {
            const current = await Deno.lstat(n.path).catch((e) => {
              if (e instanceof Deno.errors.NotFound) return null;
              throw e;
            });
            if (current && fingerprint(current) === n.stamp) {
              const v = JSON.parse(n.value);
              const logs = [n.path.replace(/\.yaml$/, ".log")];
              if (n.kind === "run") {
                logs.push(
                  join(base, "logs", String(v.workflowId), `${n.ref}.log`),
                );
                for (
                  const snapshot of db.prepare(
                    "SELECT path FROM nodes WHERE kind='snapshot' AND path LIKE ?",
                  ).all(`%/runs/${n.ref}/%`)
                ) logs.push(String(snapshot.path));
              } else if (typeof v.logFile === "string") {
                const log = resolve(repo, v.logFile);
                if (
                  log.slice(0, log.lastIndexOf("/")) ===
                    n.path.slice(0, n.path.lastIndexOf("/")) &&
                  log.endsWith(".log")
                ) logs.push(log);
              }
              for (const path of logs) {
                db.prepare("INSERT OR IGNORE INTO trash VALUES (?,?)").run(
                  path,
                  n.path,
                );
              }
              assertRetentionTarget(repo,n.path);
              await Deno.remove(n.path);
              removedPaths++;
              db.prepare("DELETE FROM files WHERE path=?").run(n.path);
              forget(n.key);
            }
          }
          set("history", n.key);
          if (removedPaths >= native.limit) break;
        } else if (phase === "cleanup") {
          const item = db.prepare("SELECT * FROM trash LIMIT 1").get() as {
            path: string;
            owner: string;
          } | undefined;
          if (!item) {
            set("phase", "done");
            break;
          }
          const owner = await Deno.lstat(item.owner).catch((e) => {
            if (e instanceof Deno.errors.NotFound) return null;
            throw e;
          });
          if (!owner) {
            const file = await Deno.lstat(item.path).catch((e) => {
              if (e instanceof Deno.errors.NotFound) return null;
              throw e;
            });
            if (file) {
              if (
                !item.path.startsWith(base + "/") || !file.isFile ||
                await Deno.realPath(item.path) !== item.path
              ) throw new Error("unsafe_retention_cleanup");
              assertRetentionTarget(repo,item.path);
              await Deno.remove(item.path);
              removedPaths++;
            }
            forget(item.path);
          }
          db.prepare("DELETE FROM trash WHERE path=?").run(item.path);
          if (removedPaths >= native.limit) break;
        } else throw new Error("invalid_retention_phase");
      } catch (error) {
        failed = true;
        throw error;
      } finally {
        if (transaction) db.exec(failed ? "ROLLBACK" : "COMMIT");
      }
    }
    return report();
  } finally {
    db.close();
    catalog.close();
  }
}
