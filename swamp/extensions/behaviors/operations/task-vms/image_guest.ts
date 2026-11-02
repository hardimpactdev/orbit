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

/** The SSH guest transport delivers the pinned artifact in agent chunks. */
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
