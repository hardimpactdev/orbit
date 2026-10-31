import {
  type SystemCommand,
  systemCommand,
} from "../processes-schedules/system.ts";
import { clickhouseFiles } from "./clickhouse_files.ts";

const Root = "/etc/orbit/analytics/clickhouse";
const directories = [
  "/etc",
  "/etc/orbit",
  "/etc/orbit/analytics",
  Root,
  `${Root}/config.d`,
  `${Root}/users.d`,
];
class Refused extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
const failed = () => new Refused("analytics.clickhouse_config_failed");
const unsafe = () => new Refused("analytics.clickhouse_config_unsafe");

/** The placed role step calls this before converging its admitted ClickHouse Process. */
export async function configureClickhouse(
  command: SystemCommand = systemCommand,
  signal?: AbortSignal,
): Promise<{ changed: boolean } | { error_code: string }> {
  async function run(program: string, args: string[], stdin?: string) {
    return await command(
      "/usr/bin/sudo",
      ["-n", "--", program, ...args],
      stdin,
      signal,
    );
  }
  async function must(program: string, args: string[], stdin?: string) {
    const result = await run(program, args, stdin);
    if (result.code !== 0) throw failed();
    return result.stdout;
  }
  async function inspect(path: string, directory: boolean) {
    // lstat semantics: GNU stat without -L sees links rather than their targets.
    const exists = await run("/usr/bin/test", ["-e", path]);
    const link = await run("/usr/bin/test", ["-L", path]);
    if (![0, 1].includes(exists.code) || ![0, 1].includes(link.code)) {
      throw failed();
    }
    if (link.code === 0) throw unsafe();
    if (exists.code === 1) return null;
    const text = await must("/usr/bin/stat", [
      "--printf",
      "%f:%u:%g:%a:%s:%h",
      "--",
      path,
    ]);
    const values = text.split(":");
    if (
      values.length !== 6 || !/^[a-f0-9]+$/.test(values[0]) ||
      values.slice(1).some((v) => !/^\d+$/.test(v))
    ) throw unsafe();
    const [type, uid, gid, mode, size, links] = values;
    const permissions = parseInt(mode, 8);
    if (
      (parseInt(type, 16) & 0xf000) !== (directory ? 0x4000 : 0x8000) ||
      Number(uid) !== 0 || Number(gid) !== 0 ||
      (directory
        ? (permissions & 0o022) !== 0
        : permissions !== 0o644 || Number(size) > 1048576 ||
          Number(links) !== 1)
    ) throw unsafe();
    return directory ? "" : await must("/usr/bin/cat", ["--", path]);
  }
  async function publish(path: string, content: string) {
    const temporary = (await must("/usr/bin/mktemp", [`${path}.XXXXXX`]))
      .trim();
    if (
      !temporary.startsWith(`${path}.`) ||
      !/^[A-Za-z0-9]+$/.test(temporary.slice(path.length + 1))
    ) throw failed();
    try {
      await must("/usr/bin/tee", ["--", temporary], content);
      await must("/usr/bin/chmod", ["0644", "--", temporary]);
      await must("/usr/bin/mv", [
        "--force",
        "--no-target-directory",
        "--",
        temporary,
        path,
      ]);
    } finally {
      await must("/usr/bin/rm", ["--force", "--", temporary]);
    }
  }
  const snapshots: { path: string; before: string | null; after: string }[] =
    [];
  const published: typeof snapshots = [];
  try {
    const absent = [];
    for (const directory of directories) {
      if (await inspect(directory, true) === null) absent.push(directory);
    }
    for (const [name, after] of Object.entries(clickhouseFiles)) {
      const path = `${Root}/${name}`;
      const before = await inspect(path, false);
      if (before !== after) snapshots.push({ path, before, after });
    }
    // Every existing path is checked before the first write.
    for (const directory of absent) {
      await must("/usr/bin/install", [
        "-d",
        "--mode",
        "0755",
        "--owner",
        "0",
        "--group",
        "0",
        directory,
      ]);
    }
    for (const snapshot of snapshots) {
      // Include an uncertain publication in recovery: rename may already have succeeded.
      published.push(snapshot);
      await publish(snapshot.path, snapshot.after);
    }
    return { changed: snapshots.length > 0 };
  } catch (error) {
    let recovered = true;
    for (const snapshot of published.reverse()) {
      try {
        if (snapshot.before === null) {
          await must("/usr/bin/rm", ["--force", "--", snapshot.path]);
        } else await publish(snapshot.path, snapshot.before);
      } catch {
        recovered = false;
      }
    }
    return {
      error_code: recovered
        ? error instanceof Refused
          ? error.code
          : "analytics.clickhouse_config_failed"
        : "analytics.clickhouse_recovery_failed",
    };
  }
}
