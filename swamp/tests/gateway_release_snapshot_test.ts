import { assert, assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";

// Runs the release's own snapshot and build scripts against a temporary base. Only `df` and the `php8.5` name are stubs:
// `df` reports a disk of FAKE_DISK_BYTES whose used bytes are the base's real bytes, so a removed file frees space.
const snapshot = new URL("../scripts/gateway-release-snapshot.sh", import.meta.url).pathname;
const build = new URL("../scripts/gateway-release-build.sh", import.meta.url).pathname;
const floor = 2 * 1024 ** 3;
const id = "abcdef012345";

function run(script: string, args: string[], base: string, disk: number) {
  const out = new Deno.Command("bash", {
    args: [script, ...args],
    env: { ORBIT_RELEASE_BASE: base, FAKE_DISK_BYTES: String(disk), PATH: `${base}/stub-bin:${Deno.env.get("PATH")}` },
  }).outputSync();
  return { code: out.code, stdout: new TextDecoder().decode(out.stdout), stderr: new TextDecoder().decode(out.stderr) };
}

function used(base: string): number {
  return Number(new TextDecoder().decode(new Deno.Command("du", { args: ["-sb", "--apparent-size", base] }).outputSync().stdout).split("\t")[0]);
}

/** A base with the live database and the given older snapshots, oldest first, each `bytes` long. */
function fixture(snapshots: string[], bytes = 300_000) {
  const base = Deno.makeTempDirSync({ prefix: "gateway-snapshot-" });
  Deno.mkdirSync(`${base}/stub-bin`);
  Deno.writeTextFileSync(`${base}/stub-bin/df`, `#!/bin/bash
used=$(du -sb --apparent-size "${base}" | cut -f1)
printf '1B-blocks Avail\\n%s %s\\n' "$FAKE_DISK_BYTES" "$((FAKE_DISK_BYTES - used))"
`);
  Deno.writeTextFileSync(`${base}/stub-bin/php8.5`, '#!/bin/sh\nexec php "$@"\n');
  Deno.chmodSync(`${base}/stub-bin/df`, 0o755);
  Deno.chmodSync(`${base}/stub-bin/php8.5`, 0o755);
  const database = `${base}/.orbit/gateway.sqlite`;
  Deno.mkdirSync(`${base}/.orbit/backups`, { recursive: true });
  const php = new Deno.Command("php", {
    args: ["-r", '$d = new SQLite3($argv[1]); $d->exec("CREATE TABLE operations (body BLOB)"); for ($i = 0; $i < 50; $i++) { $d->exec("INSERT INTO operations VALUES (randomblob(4000))"); }', database],
  }).outputSync();
  assert(php.success, new TextDecoder().decode(php.stderr));
  const app = `${base}/releases/${id}/apps/gateway/bootstrap/cache`;
  Deno.mkdirSync(app, { recursive: true });
  Deno.writeTextFileSync(`${app}/config.php`, `<?php return ['database' => ['connections' => ['sqlite' => ['database' => ${JSON.stringify(database)}]]]];\n`);
  const paths = snapshots.map((name, i) => {
    const path = `${base}/.orbit/backups/${name}`;
    Deno.writeFileSync(path, new Uint8Array(bytes));
    const time = new Date(Date.UTC(2026, 10, 14, 6, i));
    Deno.utimeSync(path, time, time);
    return path;
  });
  return { base, database, paths, size: Deno.statSync(database).size };
}

function kept(base: string): string[] {
  return [...Deno.readDirSync(`${base}/.orbit/backups`)].map((entry) => entry.name).sort();
}

const old = ["pre-111111111111-20261114T060000Z-a.sqlite", "pre-222222222222-20261114T060100Z-b.sqlite", "pre-333333333333-20261114T060200Z-c.sqlite"];

Deno.test("the snapshot keeps the newest two pre-deploy snapshots: the new one and the one before it", () => {
  const { base } = fixture(old);
  const result = run(snapshot, [id], base, floor + 500 * 1024 ** 2);
  assertEquals(result.code, 0, result.stderr);
  const path = result.stdout.trim();
  assert(path.startsWith(`${base}/.orbit/backups/pre-${id}-`) && path.endsWith(".sqlite"), path);
  assertEquals(Deno.statSync(path).mode! & 0o777, 0o600);
  assertEquals(kept(base), [old[2], path.split("/").pop()!].sort());
});

Deno.test("the snapshot always keeps the one a pause names", () => {
  const { base, paths } = fixture(old);
  Deno.writeTextFileSync(`${base}/.orbit/gateway-release.paused`, JSON.stringify({ snapshot: paths[0] }));
  const result = run(snapshot, [id], base, floor + 500 * 1024 ** 2);
  assertEquals(result.code, 0, result.stderr);
  assertEquals(kept(base), [old[0], old[2], result.stdout.trim().split("/").pop()!].sort());
});

Deno.test("the snapshot removes a stopped run's partial copy", () => {
  const { base } = fixture([]);
  Deno.writeTextFileSync(`${base}/.orbit/backups/pre-999999999999-20261114T050000Z-d.sqlite.partial`, "x");
  const result = run(snapshot, [id], base, floor + 500 * 1024 ** 2);
  assertEquals(result.code, 0, result.stderr);
  assertEquals(kept(base), [result.stdout.trim().split("/").pop()!]);
});

Deno.test("when the copy would break the floor, the snapshot first removes all but the newest older snapshot", () => {
  const { base, size } = fixture(old);
  // Room for the copy above the floor only after two of the three older snapshots (300,000 bytes each) are gone.
  const disk = floor + used(base) + size - 450_000;
  const result = run(snapshot, [id], base, disk);
  assertEquals(result.code, 0, result.stderr);
  assertEquals(kept(base), [old[2], result.stdout.trim().split("/").pop()!].sort());
});

Deno.test("when even then the copy would break the floor, the snapshot fails with gateway.release_disk_low and writes nothing", () => {
  const { base, paths, size } = fixture(old);
  Deno.writeTextFileSync(`${base}/.orbit/gateway-release.paused`, JSON.stringify({ snapshot: paths[0] }));
  const disk = floor + used(base) + size - 700_000;
  const result = run(snapshot, [id], base, disk);
  assertEquals(result.code, 1);
  assertStringIncludes(result.stderr, "gateway.release_disk_low:");
  assertEquals(result.stdout, "");
  // The newest older snapshot and the paused one stay; no copy, no partial.
  assertEquals(kept(base), [old[0], old[2]].sort());
});

Deno.test("the floor is 10 % of the disk when that is more than 2 GiB", () => {
  const { base, size } = fixture([]);
  const disk = 40 * 1024 ** 3;
  // 3.9 GiB free on a 40 GiB disk keeps 2 GiB but not 10 % free.
  const short = run(snapshot, ["--check", `${base}/.orbit/gateway.sqlite`, String(disk - used(base) - size - Math.floor(disk / 10) + 1)], base, disk);
  assertEquals(short.code, 1);
  assertStringIncludes(short.stderr, "gateway.release_disk_low:");
  const fits = run(snapshot, ["--check", `${base}/.orbit/gateway.sqlite`, String(disk - used(base) - size - Math.floor(disk / 10))], base, disk);
  assertEquals(fits.code, 0, fits.stderr);
});

Deno.test("the check counts the older snapshots the snapshot step would remove, but not the newest or the paused one", () => {
  const { base, database, paths, size } = fixture(old);
  const disk = floor + used(base) + size + 1_000_000 - 600_000;
  // Needs 600,000 bytes from the two oldest snapshots.
  assertEquals(run(snapshot, ["--check", database, "1000000"], base, disk).code, 0);
  Deno.writeTextFileSync(`${base}/.orbit/gateway-release.paused`, JSON.stringify({ snapshot: paths[0] }));
  const result = run(snapshot, ["--check", database, "1000000"], base, disk);
  assertEquals(result.code, 1);
  assertStringIncludes(result.stderr, "gateway.release_disk_low:");
  assertEquals(kept(base).filter((n) => n.startsWith("pre-")), old, "a check removes nothing");
});

Deno.test("a new build fails with gateway.release_disk_low before it writes, when the build and a snapshot do not fit above the floor", () => {
  const { base, size } = fixture([]);
  const repo = `${base}/shared/orbit.git`;
  const git = (...args: string[]) => {
    const out = new Deno.Command("git", { args: ["-C", repo, "-c", "user.name=t", "-c", "user.email=t@t", ...args] }).outputSync();
    assert(out.success, new TextDecoder().decode(out.stderr));
    return new TextDecoder().decode(out.stdout).trim();
  };
  Deno.mkdirSync(`${repo}/swamp/scripts`, { recursive: true });
  git("init", "-q");
  Deno.copyFileSync(snapshot, `${repo}/swamp/scripts/gateway-release-snapshot.sh`);
  git("add", ".");
  git("commit", "-qm", "current");
  const current = git("rev-parse", "HEAD");
  git("commit", "-q", "--allow-empty", "-m", "next");
  const next = git("rev-parse", "HEAD");
  Deno.renameSync(`${base}/releases/${id}`, `${base}/releases/${current.slice(0, 12)}`);
  Deno.writeTextFileSync(`${base}/releases/${current.slice(0, 12)}/REVISION`, `${current}\n`);
  Deno.symlinkSync(`releases/${current.slice(0, 12)}`, `${base}/orbit`);
  // Room for the snapshot, but not for the 1 GiB build as well.
  const result = run(build, [next, "false", "{}"], base, floor + used(base) + size + 1024 ** 3 - 1);
  assertEquals(result.code, 1);
  assertStringIncludes(result.stderr, "gateway.release_disk_low:");
  assertFalse([...Deno.readDirSync(`${base}/releases`)].some((entry) => entry.name === next.slice(0, 12)), "the build wrote its release");
});
