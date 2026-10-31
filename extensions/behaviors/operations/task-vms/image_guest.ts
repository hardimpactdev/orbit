import { agentReply } from "../_lib/node-agent.ts";

const agent = "/usr/local/bin/orbit-agent";
const Digest = /^[a-f0-9]{64}$/;
export async function digest(bytes: Uint8Array): Promise<string> {
  return Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new Uint8Array(bytes)),
    ),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
}

/** Fixed Incus argv; no SSH hop, shell source, or enrolled guest identity. */
export function guestArgv(project: string, name: string, argv: string[]) {
  if (
    !/^[a-z0-9][a-z0-9-]{0,62}$/.test(project) ||
    !/^tvm-image-[0-9]+(?:-attempt-[0-9]+)?(?:-smoke)?$/.test(name)
  ) {
    throw new Error("Invalid image guest");
  }
  // image.owner is an Account read. Root commands and mutation supervision still start as root.
  const uid = argv[1] === "command" && argv[2] === "image.owner" ? "1000" : "0";
  return [
    "-n",
    "--",
    "/usr/bin/incus",
    "--project",
    project,
    "exec",
    "--mode=non-interactive",
    "--user",
    uid,
    "--group",
    uid,
    "--env",
    "SUDO_USER=orbit",
    "--",
    name,
    ...argv,
  ];
}

export async function incusProcess(
  args: string[],
  input: Uint8Array,
  seconds: number,
) {
  const child = new Deno.Command("/usr/bin/sudo", {
    args,
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(seconds * 1000),
  }).spawn();
  let finished = false;
  try {
    const writer = child.stdin.getWriter();
    await writer.write(input);
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 65536) {
        throw new Error("Image guest output exceeded its bound");
      }
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return { success: status.success, bytes };
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* Already stopped. */ }
      await child.status.catch(() => {});
    }
  }
}

export function incusGuest(
  project: string,
  name: string,
  pin: string,
  invoke = incusProcess,
) {
  if (!Digest.test(pin)) throw new Error("Invalid image agent pin");
  const command = async (
    name: string,
    input: Record<string, unknown>,
    seconds = 120,
    binary = agent,
  ) => {
    const envelope = new TextEncoder().encode(
      JSON.stringify({
        agent_sha256: pin,
        deadline_ms: seconds * 1000,
        input,
      }) + "\n",
    );
    const result = await invoke(
      guestArgv(project, nameOfGuest, [binary, "command", name]),
      envelope,
      seconds + 5,
    );
    const data = agentReply(result.bytes, result.success);
    if ("error_code" in data) throw new Error(String(data.error_code));
    if (name === "agent.install") {
      if (data.sha256 !== pin || typeof data.replaced !== "boolean") {
        throw new Error("task_vm.bootstrap_failed");
      }
    } else if (
      ![
        "image.inspect-phase",
        "image.start-phase",
        "image.restart-phase",
        "pi.receive-artifact",
      ].includes(name) && data.ready !== true
    ) {
      throw new Error("task_vm.image_build_failed");
    }
    return data;
  };
  const nameOfGuest = name;
  return {
    command,
    async bootstrap() {
      // Incus's guest agent appears during boot. Only this read-only availability check retries.
      const deadline = Date.now() + 600000;
      for (;;) {
        const ready = await invoke(
          guestArgv(project, name, ["/usr/bin/true"]),
          new Uint8Array(),
          30,
        );
        if (ready.success) break;
        if (Date.now() >= deadline) {
          throw new Error("task_vm.bootstrap_timeout");
        }
        await new Promise((resolve) => setTimeout(resolve, 1000));
      }
      const cloud = await invoke(
        guestArgv(project, name, ["/usr/bin/cloud-init", "status", "--wait"]),
        new Uint8Array(),
        600,
      );
      if (!cloud.success) throw new Error("task_vm.bootstrap_failed");
      const candidate = await imageAgent(pin);
      const path = `/var/tmp/orbit-agent-candidate-${pin}`;
      const pushed = await invoke(
        [
          "-n",
          "--",
          "/usr/bin/incus",
          "--project",
          project,
          "file",
          "push",
          "--mode",
          "0700",
          "--uid",
          "0",
          "--gid",
          "0",
          "--",
          "-",
          name + path,
        ],
        candidate,
        180,
      );
      if (!pushed.success) throw new Error("task_vm.bootstrap_failed");
      await command(
        "agent.install",
        { candidate: path, sha256: pin },
        180,
        path,
      );
      await command("guest.cloud-init", {}, 45);
    },
    async artifact(bytes: Uint8Array, sha256: string) {
      await imageArtifact(bytes, sha256, command);
    },
  };
}

/** Both guest transports deliver the same pinned artifact protocol. */
export async function imageArtifact(
  bytes: Uint8Array,
  sha256: string,
  command: (
    name: string,
    input: Record<string, unknown>,
    seconds: number,
  ) => Promise<Record<string, unknown>>,
) {
  if (
    bytes.length < 1 || bytes.length > 268435456 || !Digest.test(sha256) ||
    await digest(bytes) !== sha256
  ) throw new Error("Invalid image artifact");
  let offset = 0;
  while (offset < bytes.length) {
    const chunk = bytes.subarray(offset, offset + 2097152);
    const base64 = btoa(
      Array.from(chunk, (byte) => String.fromCharCode(byte)).join(""),
    );
    const result = await command("pi.receive-artifact", {
      sha256,
      offset,
      total: bytes.length,
      bytes: base64,
    }, 120);
    const next = result.received_bytes;
    if (
      !Number.isInteger(next) || Number(next) < offset + chunk.length ||
      Number(next) > bytes.length ||
      result.verified !== (next === bytes.length)
    ) {
      throw new Error("Invalid image artifact progress");
    }
    offset = Number(next);
  }
}

export async function imageAgent(pin: string) {
  for (const path of ["/usr", "/usr/local", "/usr/local/bin", agent]) {
    const info = await Deno.lstat(path);
    if (
      info.isSymlink || info.uid !== 0 || info.mode === null ||
      (info.mode & 0o022) !== 0 ||
      (path === agent ? !info.isFile : !info.isDirectory)
    ) {
      throw new Error("Unsafe image agent path");
    }
  }
  const candidate = await Deno.readFile(agent);
  if (await digest(candidate) !== pin) {
    throw new Error("agent.version_mismatch");
  }
  return candidate;
}
