import { z } from "npm:zod@4.4.3";
import {
  type FleetContext,
  runCopy,
  runExec,
} from "https://raw.githubusercontent.com/swamp-club/swamp-extensions/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh/extensions/models/_lib/operations.ts";
import { agentReply } from "../_lib/node-agent.ts";
import { imageAgent, imageArtifact } from "./image_guest.ts";

const publicKey = z.string().regex(
  /^ssh-ed25519 [A-Za-z0-9+/]+={0,2}(?: [!-~]+)?$/,
);
export const HostKeys = z.object({
  builder: z.object({ private: z.string(), public: publicKey }).strict(),
  smoke: z.object({ private: z.string(), public: publicKey }).strict(),
}).strict();
export type SshEnvironment = Pick<FleetContext, "createCelEnvironment">;
export type SshTarget = {
  name: string;
  address: string;
  publicKey: string;
  identity: string;
};
const refused = () => new Error("task_vm.image_channel_lost");

/** Reuse the pinned official SSH library. Its per-call output stays in this step, never a second run. */
export async function imageSsh(
  target: SshTarget,
  environment: SshEnvironment,
  argv: string[],
  stdin: string | undefined,
  seconds: number,
  upload?: { src: string; dst: string },
): Promise<string> {
  const address = z.ipv4().parse(target.address);
  if (
    /^(?:0|10|127|169\.254|172\.(?:1[6-9]|2[0-9]|3[01])|192\.168|22[4-9]|23[0-9]|24[0-9]|25[0-5])\./
      .test(address)
  ) {
    throw refused();
  }
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
    signal: AbortSignal.timeout((seconds + 5) * 1000),
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
    modelType: "@nckrtl/task-vm-upcloud-image-build",
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
    if (keyRefused) throw new Error("task_vm.host_key_failed");
    throw refused();
  }
  if (output === undefined) throw refused();
  return output;
}

export function sshImageGuest(
  target: SshTarget,
  pin: string,
  environment: SshEnvironment,
  invoke = imageSsh,
) {
  const command = async (
    name: string,
    input: Record<string, unknown>,
    seconds = 120,
    binary = "/usr/local/bin/orbit-agent",
  ) => {
    const argv = [
      ...(name === "image.owner" ? [] : ["sudo", "-n"]),
      binary,
      "command",
      name,
    ];
    const stdout = await invoke(
      target,
      environment,
      argv,
      JSON.stringify({
        agent_sha256: pin,
        deadline_ms: seconds * 1000,
        input,
      }) + "\n",
      seconds + 5,
    );
    const result = agentReply(new TextEncoder().encode(stdout), true);
    if ("error_code" in result) throw new Error(String(result.error_code));
    if (name === "agent.install") {
      if (result.sha256 !== pin || typeof result.replaced !== "boolean") {
        throw new Error("task_vm.bootstrap_failed");
      }
    } else if (
      !["image.inspect-phase", "image.start-phase", "pi.receive-artifact"]
        .includes(name) && result.ready !== true
    ) {
      throw new Error("task_vm.image_build_failed");
    }
    return result;
  };
  return {
    command,
    async bootstrap() {
      const deadline = Date.now() + 600000;
      for (;;) {
        try {
          await invoke(target, environment, ["/usr/bin/true"], undefined, 10);
          break;
        } catch (error) {
          if (
            !(error instanceof Error) ||
            error.message !== "task_vm.image_channel_lost"
          ) throw error;
          if (Date.now() >= deadline) {
            throw new Error("task_vm.bootstrap_timeout");
          }
        }
        await new Promise((resolve) => setTimeout(resolve, 1000));
      }
      await invoke(
        target,
        environment,
        ["sudo", "-n", "/usr/bin/cloud-init", "status", "--wait"],
        undefined,
        600,
      );
      await imageAgent(pin);
      const dir = (await invoke(
        target,
        environment,
        ["/usr/bin/mktemp", "-d", "/tmp/orbit-image-agent-XXXXXXXXXXXX"],
        undefined,
        10,
      )).trim();
      if (!/^\/tmp\/orbit-image-agent-[A-Za-z0-9]{12}$/.test(dir)) {
        throw refused();
      }
      try {
        const candidate = dir + "/agent";
        await invoke(target, environment, [], undefined, 180, {
          src: "/usr/local/bin/orbit-agent",
          dst: candidate,
        });
        await command(
          "agent.install",
          { candidate, sha256: pin },
          180,
          candidate,
        );
      } finally {
        await invoke(
          target,
          environment,
          ["/usr/bin/rm", "-rf", "--", dir],
          undefined,
          10,
        );
      }
      await command("guest.cloud-init", {}, 45);
    },
    artifact(bytes: Uint8Array, sha256: string) {
      return imageArtifact(bytes, sha256, command);
    },
  };
}

/** Private keys are generated on the build worker, then stored through the existing encrypted output boundary. */
export async function imageHostKeys(
  name: string,
): Promise<z.infer<typeof HostKeys>> {
  if (!/^tvm-image-[0-9]{14}$/.test(name)) {
    throw new Error("task_vm.host_key_failed");
  }
  const dir = await Deno.makeTempDir({ prefix: "orbit-image-host-key-" });
  await Deno.chmod(dir, 0o700);
  const pair = async (slot: string) => {
    const path = dir + "/" + slot;
    const result = await new Deno.Command("/usr/bin/ssh-keygen", {
      args: [
        "-q",
        "-t",
        "ed25519",
        "-N",
        "",
        "-C",
        name + "-" + slot,
        "-f",
        path,
      ],
      stdout: "null",
      stderr: "null",
      signal: AbortSignal.timeout(30000),
    }).output();
    if (!result.success) throw new Error("task_vm.host_key_failed");
    return {
      private: await Deno.readTextFile(path),
      public: (await Deno.readTextFile(path + ".pub")).trim(),
    };
  };
  try {
    return HostKeys.parse({
      builder: await pair("builder"),
      smoke: await pair("smoke"),
    });
  } finally {
    await Deno.remove(dir, { recursive: true });
  }
}
