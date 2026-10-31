import { z } from "npm:zod@4.4.3";
import { localCommand, localFilesystem } from "../_lib/local-worker.ts";
export const SshHostKey = z.object({
  private: z.string().max(16384).regex(
    /^-----BEGIN OPENSSH PRIVATE KEY-----\n[A-Za-z0-9+/=\n]+\n-----END OPENSSH PRIVATE KEY-----\n?$/,
  ),
  public: z.string().max(1024).regex(
    /^ssh-ed25519 [A-Za-z0-9+/]+={0,2}(?: [!-~]+)?$/,
  ),
}).strict();
export type SshHostKeyPair = z.infer<typeof SshHostKey>;

/** The same worker-local key generation serves image builders and a new Task VM. */
export async function generateSshHostKey(
  name: string,
): Promise<SshHostKeyPair> {
  if (!/^tvm-(?:[1-9][0-9]*|image-[0-9]{14}-(?:builder|smoke))$/.test(name)) {
    throw new Error("task_vm.host_key_failed");
  }
  const dir = await localFilesystem().makeTempDir({
    prefix: "orbit-host-key-",
  });
  try {
    await localFilesystem().chmod(dir, 0o700);
    const path = dir + "/key";
    const result = await localCommand("/usr/bin/ssh-keygen", {
      args: ["-q", "-t", "ed25519", "-N", "", "-C", name, "-f", path],
      stdout: "null",
      stderr: "null",
      signal: AbortSignal.timeout(30000),
    }).output();
    if (!result.success) throw new Error("task_vm.host_key_failed");
    return SshHostKey.parse({
      private: await localFilesystem().readTextFile(path),
      public: (await localFilesystem().readTextFile(path + ".pub")).trim(),
    });
  } finally {
    await localFilesystem().remove(dir, { recursive: true });
  }
}
