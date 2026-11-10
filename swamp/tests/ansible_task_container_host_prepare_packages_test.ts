import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, load, plays, play, GiB, Task, local, transform, fixture } from "./_support/ansible_task_container_host_prepare.ts";

Deno.test({
  name:
    "a host without Incus installs the recipe's pinned packages, fetched by digest",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ installed: "" });
    try {
      const result = f.run();
      assert(result.ok, result.output);
      const debs = ["incus", "incus-base", "incus-client"].map((name, i) =>
        `${f.folder}/debs/${name}_${String(i + 1).repeat(64)}.deb`
      );
      assertEquals(
        f.read("apt-get").trim(),
        `install --yes --no-install-recommends --no-remove -- ${
          debs.join(" ")
        }`,
      );
      const fetched = JSON.parse(Deno.readTextFileSync(debs[1]));
      assertEquals(fetched.sha256, "2".repeat(64));
      assertEquals(
        fetched.urls[0],
        `https://mirror.example/incus-base_${"2".repeat(64)}.deb`,
      );
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name:
    "an Incus package removed with its config files left counts as missing and is installed",
  ignore: !hasAnsible,
  fn() {
    const f = fixture({ installed: "config-files" });
    try {
      const result = f.run();
      assert(result.ok, result.output);
      assertStringIncludes(
        f.read("apt-get"),
        "install --yes --no-install-recommends --no-remove --",
      );
    } finally {
      f.cleanup();
    }
  },
});
