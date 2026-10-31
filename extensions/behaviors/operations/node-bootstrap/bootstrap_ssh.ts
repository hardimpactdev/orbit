import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
import {
  type FleetContext,
  runCopy,
  runExec,
} from "https://raw.githubusercontent.com/swamp-club/swamp-extensions/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh/extensions/models/_lib/operations.ts";

/** Public endpoint admitted by the owning Node parent. Identity stays callback-local. */
export const BootstrapTarget = z.object({
  node_id: z.number().int().positive(),
  host: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9.:-]{0,252}$/),
  port: z.number().int().min(1).max(65535),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  fingerprint: z.string().regex(/^SHA256:[A-Za-z0-9+/]{43}$/),
}).strict();
export type BootstrapTarget = z.infer<typeof BootstrapTarget>;
export type BootstrapEnvironment = Pick<FleetContext, "createCelEnvironment">;
type Upload = { src: string; dst: string };
export type BootstrapSsh = (
  target: BootstrapTarget,
  identity: string,
  environment: BootstrapEnvironment,
  argv: string[],
  stdin: string | undefined,
  seconds: number,
  upload?: Upload,
  signal?: AbortSignal,
) => Promise<string>;

const unavailable = () => new Error("node.bootstrap_channel_uncertain");
const PublicKey = z.string().regex(
  /^(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,2}$/,
);

/** A key scan is observation only. Only the admitted fingerprint grants trust. */
export async function bootstrapHostKey(
  target: BootstrapTarget,
  scan = async (argv: string[], signal?: AbortSignal) => {
    const result = await localCommand("/usr/bin/ssh-keyscan", {
      args: argv,
      stdout: "piped",
      stderr: "null",
      signal,
    }).output();
    if (!result.success || result.stdout.length > 65536) throw unavailable();
    return new TextDecoder().decode(result.stdout);
  },
  signal?: AbortSignal,
) {
  const input = BootstrapTarget.parse(target);
  signal?.throwIfAborted();
  const timeout = AbortSignal.timeout(15000);
  const text = await scan(
    ["-T", "10", "-p", String(input.port), input.host],
    signal ? AbortSignal.any([signal, timeout]) : timeout,
  );
  if (text.length > 65536) throw unavailable();
  for (const line of text.split("\n")) {
    const fields = line.trim().split(/\s+/);
    if (fields.length !== 3) continue;
    const key = PublicKey.safeParse(fields[1] + " " + fields[2]);
    if (!key.success) continue;
    let blob: Uint8Array;
    try {
      blob = Uint8Array.from(atob(fields[2]), (char) => char.charCodeAt(0));
    } catch {
      continue;
    }
    const digest = new Uint8Array(
      await crypto.subtle.digest("SHA-256", new Uint8Array(blob)),
    );
    const fingerprint = "SHA256:" +
      btoa(String.fromCharCode(...digest)).replace(/=+$/, "");
    if (fingerprint === input.fingerprint) return key.data;
  }
  throw new Error("node.ssh_host_key_mismatch");
}

/** Pinned official SSH, same native run; output never enters a second resource. */
export const bootstrapSsh: BootstrapSsh = async (
  raw,
  identity,
  environment,
  argv,
  stdin,
  seconds,
  upload,
  signal,
) => {
  const target = BootstrapTarget.parse(raw);
  if (!identity.startsWith("/") || /[\0\r\n]/.test(identity)) {
    throw unavailable();
  }
  const file = await Deno.lstat(identity);
  if (
    !file.isFile || file.isSymlink || file.mode === null ||
    (file.mode & 0o077) !== 0 || file.uid !== Deno.uid()
  ) throw unavailable();
  signal?.throwIfAborted();
  const key = await bootstrapHostKey(target, undefined, signal);
  let output: string | undefined;
  let refusedKey = false;
  const quiet = () => {};
  const timeout = AbortSignal.timeout((seconds + 5) * 1000);
  const context: FleetContext = {
    ...environment,
    signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
    globalArgs: {
      name: "node-bootstrap-" + target.node_id,
      hosts: [{ name: "node", address: target.host }],
      transport: {
        kind: "ssh",
        user: target.user,
        port: target.port,
        identityFile: identity,
        identitiesOnly: true,
        hostKeyPolicy: "pinned",
        hostKeys: [key],
        controlMaster: { enabled: false },
      },
    },
    modelType: "@nckrtl/node-add-direct",
    modelId: "node-bootstrap-" + target.node_id,
    methodName: "step",
    logger: { debug: quiet, info: quiet, warn: quiet, error: quiet },
    readResource: () => Promise.resolve(null),
    dataRepository: {
      findAllForModel: () => Promise.resolve([]),
      delete: () => Promise.reject(unavailable()),
    },
    writeResource(_spec, name, raw) {
      refusedKey = typeof raw.stderr === "string" &&
        /host key verification failed|REMOTE HOST IDENTIFICATION HAS CHANGED|host key is known .*strict checking/i
          .test(raw.stderr);
      if (
        raw.exitCode !== 0 || typeof raw.stdout !== "string" ||
        raw.stdout.length > 65536
      ) throw unavailable();
      output = raw.stdout;
      return Promise.resolve({ name });
    },
  };
  try {
    if (upload) {
      await runCopy({
        hosts: ["node"],
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
        { hosts: ["node"], argv, stdin, timeoutSec: seconds },
        context,
      );
    }
  } catch {
    if (refusedKey) throw new Error("node.ssh_host_key_mismatch");
    throw unavailable();
  }
  if (output === undefined) throw unavailable();
  return output;
};

/** Code-owned lower actions call system commands through the same admitted SSH session.
 * No program/argv is accepted from a workflow input or Gateway request.
 */
export function bootstrapSystem(
  raw: BootstrapTarget,
  identity: string,
  environment: BootstrapEnvironment,
  invoke: BootstrapSsh = bootstrapSsh,
  platform: "linux" | "macos" = "linux",
) {
  const target = BootstrapTarget.parse(raw);
  return async (
    argv: readonly string[],
    stdin?: string,
    signal?: AbortSignal,
  ) => {
    const stdout = await invoke(
      target,
      identity,
      environment,
      [
        ...(platform === "linux" && target.user !== "root"
          ? ["/usr/bin/sudo", "-n", "--"]
          : []),
        ...argv,
      ],
      stdin,
      660,
      undefined,
      signal,
    );
    return { success: true, stdout };
  };
}
