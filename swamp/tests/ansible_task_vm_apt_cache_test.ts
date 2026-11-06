/** A task VM caches the Ubuntu snapshot indexes in its own apt-cacher-ng, and every apt read of the snapshot goes through it. */
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

const tasksDirectory = new URL("../ansible/tasks/", import.meta.url);
const text = (name: string) =>
    Deno.readTextFileSync(new URL(name, tasksDirectory));
// deno-lint-ignore no-explicit-any
const tasks = (name: string) => parse(text(name)) as Record<string, any>[];

Deno.test("the software phase starts with the cache, before any read of the snapshot", () => {
    const [first, second] = tasks("task-vm-software.yml");
    assertEquals(
        first["ansible.builtin.import_tasks"],
        "task-vm-apt-cache.yml",
    );
    assertEquals(second["ansible.builtin.import_tasks"], "task-vm-apt.yml");
});

Deno.test("the index read goes through the cache over plain http; the packages stay a direct download", () => {
    const config = text("task-vm-apt-config.yml");
    assertStringIncludes(
        config,
        "URIs: https://snapshot.ubuntu.com/ubuntu/{{ recipe.ubuntu.snapshot }}/",
    );
    assertStringIncludes(
        config,
        "URIs: http://snapshot.ubuntu.com/ubuntu/{{ recipe.ubuntu.snapshot }}/",
    );
    const proxied = config.split("apt-cache.conf")[1];
    assertStringIncludes(
        proxied,
        'Acquire::http::Proxy "http://localhost:3142";',
    );
    // apt pipelines its requests; the cache answers some of them with the body of another file.
    assertStringIncludes(proxied, 'Acquire::http::Pipeline-Depth "0";');
    // The configuration of the installs has no proxy: through the cache most packages failed.
    const direct = config.split("apt-cache.conf")[0].split(
        "{{ apt_directory }}/apt.conf",
    )[1];
    assertEquals(direct.includes("Proxy"), false);
    const read = tasks("task-vm-apt-config.yml").find((task) =>
        task.name === "Read the package indexes"
    )!;
    assertEquals(read.environment, "{{ apt_index_environment }}");
});

Deno.test("the cache installs from the own sources of the VM and keeps indexes, not packages", () => {
    const byName = Object.fromEntries(
        tasks("task-vm-apt-cache.yml").map((task) => [task.name, task]),
    );
    const install = byName["Install the package cache"];
    assertEquals(install["ansible.builtin.apt"].name, "apt-cacher-ng");
    assertEquals(
        "APT_CONFIG" in install.environment,
        false,
        "the cache comes before the snapshot configuration",
    );
    assertEquals(install.until, "apt_cache_install is succeeded");
    assertEquals([install.retries, install.delay], [
        "{{ apt_retries }}",
        "{{ apt_delay }}",
    ]);
    // The cache serves the indexes only: no rule for packages, no pattern for static files (both broke the answers); it asks the
    // snapshot service again before it answers with an error.
    const content =
        byName["Let the package cache ask the snapshot service again"][
            "ansible.builtin.copy"
        ].content;
    assertStringIncludes(content, "DlMaxRetries: 10");
    assertEquals(
        content.includes("DontCache") || content.includes("SPfilePatternEx"),
        false,
    );
    const service = byName["Run the package cache"]["ansible.builtin.systemd"];
    assertEquals([service.name, service.enabled], [
        "apt-cacher-ng.service",
        true,
    ]);
    assertStringIncludes(service.state, "restarted");
});

Deno.test("the cache opens on the nested bridge and stays in the image", () => {
    assertStringIncludes(
        text("task-vm-nested-firewall.yml"),
        '{ proto: tcp, port: "3142" }',
    );
    // The clean phase and the download removal name their paths; none is the cache or its parent.
    for (const name of ["task-vm-image-cache.yml", "task-vm-clean.yml"]) {
        const source = text(name);
        assertEquals(source.includes("apt-cacher-ng"), false, name);
        assertEquals(
            /path:\s*"?\/var\/cache\/?["\s},]/.test(source),
            false,
            name,
        );
    }
});

Deno.test("the end check reads the cache", () => {
    const check = text("task-vm-check.yml");
    assertStringIncludes(check, "apt-cacher-ng.service");
    assertStringIncludes(check, "seen.cache == 'active'");
});
