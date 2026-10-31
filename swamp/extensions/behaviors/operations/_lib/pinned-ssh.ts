import { z } from "npm:zod@4.4.3";
import {
  type FleetContext,
  runCopy,
  runExec,
} from "https://raw.githubusercontent.com/swamp-club/swamp-extensions/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh/extensions/models/_lib/operations.ts";
const publicKey = z.string().regex(
  /^ssh-ed25519 [A-Za-z0-9+/]+={0,2}(?: [!-~]+)?$/,
);
export type SshEnvironment = Pick<FleetContext, "createCelEnvironment">;
export type SshTarget = {
  name: string;
  address: string;
  publicKey: string;
  identity: string;
};
const refused = () => new Error("operation.ssh_channel_lost");

/** Reuse the pinned official SSH library. Its per-call output stays in this step, never a second run. */
export async function pinnedSsh(
  target: SshTarget,
  environment: SshEnvironment,
  argv: string[],
  stdin: string | undefined,
  seconds: number,
  upload?: { src: string; dst: string },
  signal?: AbortSignal,
): Promise<string> {
  signal?.throwIfAborted();
  const address = z.ipv4().parse(target.address);
  publicKey.parse(target.publicKey);
  if (!target.identity.startsWith("/") || target.identity.includes("\0")) {
    throw refused();
  }
  const identity = await Deno.lstat(target.identity);
  if (
    !identity.isFile || identity.isSymlink || identity.mode === null ||
    (identity.mode & 0o077) !== 0
  ) throw refused();
  let output: string | undefined;
  let keyRefused = false;
  const quiet = () => {};
  const context: FleetContext = {
    ...environment,
    signal: signal
      ? AbortSignal.any([signal, AbortSignal.timeout((seconds + 5) * 1000)])
      : AbortSignal.timeout((seconds + 5) * 1000),
    globalArgs: {
      name: target.name,
      hosts: [{ name: "guest", address }],
      transport: {
        kind: "ssh",
        user: "orbit",
        identityFile: target.identity,
        identitiesOnly: true,
        hostKeyPolicy: "pinned",
        hostKeys: [target.publicKey],
        controlMaster: { enabled: false },
      },
    },
    modelType: "@nckrtl/pinned-node-ssh",
    modelId: target.name,
    methodName: "step",
    logger: { debug: quiet, info: quiet, warn: quiet, error: quiet },
    readResource: () => Promise.resolve(null),
    dataRepository: {
      findAllForModel: () => Promise.resolve([]),
      delete: () => Promise.reject(refused()),
    },
    writeResource(_spec, name, raw) {
      keyRefused = typeof raw.stderr === "string" &&
        /host key verification failed|REMOTE HOST IDENTIFICATION HAS CHANGED|host key is known .*strict checking/i
          .test(raw.stderr);
      if (
        raw.exitCode !== 0 || typeof raw.stdout !== "string" ||
        raw.stdout.length > 65536
      ) throw refused();
      output = raw.stdout;
      return Promise.resolve({ name });
    },
  };
  try {
    if (upload) {
      await runCopy({
        hosts: ["guest"],
        src: upload.src,
        dst: upload.dst,
        direction: "to",
        atomic: true,
        verifySha256: true,
        mode: "0700",
        timeoutSec: seconds,
      }, context);
    } else {
      await runExec(
        { hosts: ["guest"], argv, stdin, timeoutSec: seconds },
        context,
      );
    }
  } catch {
    if (keyRefused) throw new Error("operation.ssh_host_key_failed");
    throw refused();
  }
  if (output === undefined) throw refused();
  return output;
}
