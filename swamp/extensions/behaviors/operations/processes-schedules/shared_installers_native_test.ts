import { assertEquals } from "jsr:@std/assert@1.0.14";
import { fromFileUrl } from "jsr:@std/path@1.1.2";

for (const [name, module, directory] of [
  ["Process definition, deploy and transfer installer parents", "process_instance_install_native_test", "."],
  ["full Instance transfer ordering and recovery", "transfer_native_test", "../instance-lifecycle/"],
]) {
  Deno.test("native " + name, async () => {
    const result = await new Deno.Command("/usr/bin/python3", {
      args: ["-m", "unittest", module, "-q"],
      cwd: fromFileUrl(new URL(directory, import.meta.url)),
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr));
  });
}
