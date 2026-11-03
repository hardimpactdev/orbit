import { assert } from "jsr:@std/assert@1.0.19";
import { type NativeRepo, ROOT } from "./native_harness.ts";

export { command, type Json, outputs, rewrite } from "./task_remote.ts";

/** Generate a real synthetic SSH identity, then remove its plaintext files before scanning. */
export async function identity(repo: NativeRepo) {
  const key = `${repo.dir}/identity`;
  const result = await new Deno.Command("ssh-keygen", {
    args: ["-q", "-t", "ed25519", "-N", "", "-f", key],
    stdout: "piped",
    stderr: "piped",
  }).output();
  assert(result.success, new TextDecoder().decode(result.stderr));
  const privateKey = await Deno.readTextFile(key), publicKey = (await Deno.readTextFile(`${key}.pub`)).trim();
  await Deno.remove(key);
  await Deno.remove(`${key}.pub`);
  repo.secrets.push(privateKey);
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  await repo.cli(["vault", "put", "orbit-control-inputs", "bootstrap-identity-node-14"], { input: privateKey });
  return publicKey;
}

export async function copy(repo: NativeRepo, source: string, target = source) {
  const path = `${repo.dir}/${target}`;
  await Deno.mkdir(path.slice(0, path.lastIndexOf("/")), { recursive: true });
  await Deno.copyFile(`${ROOT}/${source}`, path);
}
