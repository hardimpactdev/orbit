import {
  type BootstrapEnvironment,
  bootstrapSsh,
} from "../node-bootstrap/bootstrap_ssh.ts";
import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

const Guest = z.object({
  task_vm_id: z.number().int().positive(),
  project: z.string().regex(/^[a-z0-9][a-z0-9-]{0,62}$/),
  name: z.string().regex(/^tvm-[1-9][0-9]*$/),
}).strict().refine((guest) => guest.name === `tvm-${guest.task_vm_id}`);

const SshFirstVisit = z.object({
  kind: z.literal("ssh"),
  address: z.ipv4(),
  publicKey: z.string().regex(/^ssh-ed25519 [A-Za-z0-9+/]+={0,2}(?: [!-~]+)?$/),
  identity: z.string().startsWith("/").max(4096),
}).transform(({ kind: _kind, ...target }) => target);

/** The admitted Incus VM is reachable before its own trusted Node worker exists. */
export function incusGuestSystem(
  raw: z.infer<typeof Guest>,
  guard: () => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
): SystemTransport {
  const guest = Guest.parse(raw);
  return async (command, signal) => {
    signal?.throwIfAborted();
    await guard();
    return await invoke({
      ...command,
      command: "/usr/bin/sudo",
      args: [
        "-n",
        "--",
        "/usr/bin/incus",
        "--project",
        guest.project,
        "exec",
        "--mode=non-interactive",
        "--user",
        "1000",
        "--group",
        "1000",
        "--env",
        "SUDO_USER=orbit",
        "--",
        guest.name,
        command.command,
        ...command.args,
      ],
    }, signal);
  };
}

/** Existing managed SSH material stays inside the shared private-read callback. */
export function sshGuestSystem(
  guest: { node_id: number; name: string },
  guard: () => Promise<void>,
  material: Pick<
    import("../_lib/direct.ts").GatewayOperation,
    "withPrivateMaterial"
  >,
  environment: BootstrapEnvironment,
  invoke = bootstrapSsh,
): SystemTransport {
  if (
    !Number.isSafeInteger(guest.node_id) || guest.node_id < 1 ||
    !/^tvm-[1-9][0-9]*$/.test(guest.name)
  ) {
    throw new Error("task_vm.bootstrap_failed");
  }
  return async (command, signal) => {
    signal?.throwIfAborted();
    await guard();
    let output: { success: boolean; stdout: string } | undefined;
    await material.withPrivateMaterial(async (raw) => {
      try {
        const target = SshFirstVisit.parse(raw.first_visit);
        const key = target.publicKey.split(" ")[1];
        const digest = new Uint8Array(
          await crypto.subtle.digest(
            "SHA-256",
            Uint8Array.from(atob(key), (character) => character.charCodeAt(0)),
          ),
        );
        const fingerprint = "SHA256:" +
          btoa(String.fromCharCode(...digest)).replace(/=+$/, "");
        await guard();
        const stdout = await invoke(
          {
            node_id: guest.node_id,
            host: target.address,
            port: 22,
            user: "orbit",
            fingerprint,
          },
          target.identity,
          environment,
          [command.command, ...command.args],
          command.stdin,
          Math.ceil(command.timeoutMs / 1000),
          undefined,
          signal,
        );
        output = { success: true, stdout };
      } catch {
        throw new Error("task_vm.bootstrap_failed");
      }
    });
    if (output === undefined) throw new Error("task_vm.bootstrap_failed");
    return output;
  };
}
