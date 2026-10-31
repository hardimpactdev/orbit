import type { NodeCommand } from "./command.ts";
import {
  metadata,
  owned,
  privateDirectory,
  publishRootFile,
  required,
  text,
} from "./files.ts";

import {
  availableMemory,
  binary,
  checker,
  credentialsRoot,
  root,
  Swamp,
  unit,
  unitPath,
  WorkerCaFile,
  WorkerConfig,
  workerUnit,
} from "./config.ts";
import type { WorkerCredentials } from "./config.ts";
export { availableMemory, Swamp, WorkerCaFile, WorkerConfig, workerUnit } from "./config.ts";
export type { WorkerCredentials } from "./config.ts";

const checkProgram = `#!/usr/bin/python3
import os,stat,sys
try:
 rows=[s for s in open('/proc/meminfo') if s.startswith('MemAvailable:')]
 fields=rows[0].split() if len(rows)==1 else []
 if len(fields)!=3 or fields[2]!='kB' or not fields[1].isdigit() or int(fields[1])*1024<${
  Swamp.bytes + Swamp.headroom
}:raise RuntimeError()
 for path,mode,directory in [('${credentialsRoot}',0o700,True),('${credentialsRoot}/enrollment.token',0o600,False),('${credentialsRoot}/server.token',0o600,False)]:
  s=os.lstat(path)
  if s.st_uid!=0 or stat.S_IMODE(s.st_mode)!=mode or not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode)):raise RuntimeError()
 if os.geteuid()!=0:raise RuntimeError()
except Exception:
 print('Node worker credential custody or available RAM check failed',file=sys.stderr);sys.exit(1)
`;

/** Private credentials remain callback-local. No Gateway admission or agent action occurs here. */
export async function configureNodeWorker(
  config: WorkerConfig | null,
  credentials: WorkerCredentials | null,
  invoke: NodeCommand,
): Promise<{ configured: boolean; changed: boolean }> {
  if (config === null) {
    const state = await invoke(
      ["/usr/bin/systemctl", "show", unit, "-p", "LoadState"],
      undefined,
      "root",
    );
    if (
      text(state.stdout).includes("LoadState=masked") ||
      await metadata(invoke, unitPath) === null
    ) return { configured: false, changed: false };
    if (!owned(await metadata(invoke, unitPath), "file", 0o644)) {
      throw new Error("swamp.node_worker_invalid");
    }
    await required(invoke, [
      "/usr/bin/systemctl",
      "disable",
      "--now",
      "--no-block",
      unit,
    ]);
    await required(invoke, ["/usr/bin/rm", "--", unitPath]);
    await required(invoke, ["/usr/bin/systemctl", "daemon-reload"]);
    return { configured: false, changed: true };
  }
  const input = WorkerConfig.parse(config);
  if ([1, 18].includes(input.node_id)) {
    throw new Error("Proxy Nodes do not install Swamp locally");
  }
  if (credentials !== null) {
    for (
      const [name, value] of [[
        `orbit-node-${input.node_id}`,
        credentials.enrollment_token,
      ], [`orbit-node-${input.node_id}-access`, credentials.server_token]]
    ) {
      if (
        value.length > 4096 || !value.startsWith(name + ".") ||
        !/^[a-z0-9-]+\.[A-Za-z0-9_-]{16,4096}$/.test(value)
      ) throw new Error("swamp.node_worker_invalid");
    }
  }
  availableMemory(
    text(await required(invoke, ["/usr/bin/cat", "/proc/meminfo"])),
  );
  const account = text(
    await required(invoke, ["/usr/bin/getent", "passwd", input.user]),
  ).trim().split(":");
  const uid = Number(account[2]);
  const gid = Number(account[3]);
  if (
    account.length !== 7 || account[0] !== input.user ||
    !Number.isSafeInteger(uid) || uid <= 0 || !Number.isSafeInteger(gid) ||
    gid <= 0
  ) throw new Error("swamp.node_worker_invalid");
  await required(invoke, [
    "/usr/sbin/runuser",
    "-u",
    input.user,
    "--",
    "/usr/bin/sudo",
    "-n",
    "/usr/bin/true",
  ]);
  if (
    text(await required(invoke, ["/usr/bin/uname", "-m"])).trim() !== "x86_64"
  ) throw new Error("swamp.node_worker_invalid");
  for (
    const path of ["/usr", "/usr/local", "/usr/local/bin", "/etc/orbit/agent"]
  ) {
    const m = await metadata(invoke, path);
    if (
      !m || m.kind !== "directory" || m.uid !== 0 || m.gid !== 0 ||
      (m.mode & 0o022) !== 0
    ) throw new Error("swamp.node_worker_invalid");
  }
  let changed = false;
  const digest = await invoke(
    ["/usr/bin/sha256sum", binary],
    undefined,
    "root",
  );
  if (
    !owned(await metadata(invoke, binary), "file", 0o755) ||
    text(digest.stdout) !== `${Swamp.sha256}  ${binary}\n`
  ) {
    const stage = text(
      await required(invoke, [
        "/usr/bin/mktemp",
        "-d",
        "-p",
        "/usr/local/bin",
        ".orbit-swamp-XXXXXXXX",
      ]),
    ).trim();
    if (!/^\/usr\/local\/bin\/\.orbit-swamp-[A-Za-z0-9]{8}$/.test(stage)) {
      throw new Error("swamp.node_worker_invalid");
    }
    try {
      const staged = stage + "/swamp";
      await required(invoke, [
        "/usr/bin/curl",
        "--fail",
        "--silent",
        "--show-error",
        "--location",
        "--proto",
        "=https",
        "--proto-redir",
        "=https",
        "--connect-timeout",
        "8",
        "--max-time",
        "300",
        "--output",
        staged,
        `https://github.com/swamp-club/swamp/releases/download/v${Swamp.version}/swamp-linux-x86_64`,
      ]);
      if (
        text(await required(invoke, ["/usr/bin/sha256sum", staged])) !==
          `${Swamp.sha256}  ${staged}\n`
      ) throw new Error("swamp.node_worker_invalid");
      await required(invoke, ["/usr/bin/chmod", "0755", staged]);
      await required(invoke, ["/usr/bin/mv", "-T", "--", staged, binary]);
      changed = true;
    } finally {
      await invoke(
        ["/usr/bin/rm", "-f", "--", stage + "/swamp"],
        undefined,
        "root",
      );
      await invoke(["/usr/bin/rmdir", "--", stage], undefined, "root");
    }
  }
  await privateDirectory(invoke, credentialsRoot, 0, 0);
  if (credentials !== null) {
    changed = await publishRootFile(
      invoke,
      credentialsRoot + "/enrollment.token",
      credentials.enrollment_token,
      0o600,
    ) || changed;
    changed = await publishRootFile(
      invoke,
      credentialsRoot + "/server.token",
      credentials.server_token,
      0o600,
    ) || changed;
  }
  for (const name of ["enrollment", "server"]) {
    if (
      !owned(
        await metadata(invoke, `${credentialsRoot}/${name}.token`),
        "file",
        0o600,
      )
    ) throw new Error("swamp.node_worker_not_ready");
  }
  await privateDirectory(invoke, root, uid, gid);
  if (
    !owned(await metadata(invoke, "/etc/orbit/agent/ca.pem"), "file", 0o644)
  ) throw new Error("swamp.node_worker_invalid");
  changed = await publishRootFile(
    invoke,
    WorkerCaFile,
    text(await required(invoke, ["/usr/bin/cat", "/etc/orbit/agent/ca.pem"])),
    0o644,
  ) || changed;
  await required(invoke, [
    "/usr/bin/install",
    "-d",
    "-m",
    "0755",
    "-o",
    "0",
    "-g",
    "0",
    "/usr/local/libexec",
  ]);
  changed = await publishRootFile(invoke, checker, checkProgram, 0o755) ||
    changed;
  changed = await publishRootFile(invoke, unitPath, workerUnit(input), 0o644) ||
    changed;
  await required(invoke, ["/usr/bin/systemctl", "daemon-reload"]);
  await required(invoke, ["/usr/bin/systemctl", "enable", unit]);
  await required(invoke, ["/usr/bin/systemctl", "reset-failed", unit]);
  await required(invoke, [
    "/usr/bin/systemctl",
    changed ? "restart" : "start",
    "--no-block",
    unit,
  ]);
  return { configured: true, changed };
}
