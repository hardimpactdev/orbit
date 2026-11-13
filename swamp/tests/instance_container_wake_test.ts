/** The Node's fast wake of a frozen Instance container (ansible/files/instance-container-wake.sh), run against a stub `incus`. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

const script = fileURLToPath(new URL("../ansible/files/instance-container-wake.sh", import.meta.url));
const gateway = (path: string) => Deno.readTextFileSync(new URL(`../../apps/gateway/app/${path}`, import.meta.url));

type Node = { status?: string; marker?: boolean; resumeFails?: boolean; web?: string[] };

/** A Node folder with the request folder, the marker folder, a cgroup tree and a stub incus that logs each call. */
async function node(id: string, state: Node) {
    const folder = await Deno.makeTempDir();
    for (const dir of ["bin", "requests", "markers", `cgroup/lxc.payload.orbit-instances_instance-${id}`]) {
        await Deno.mkdir(`${folder}/${dir}`, { recursive: true });
    }
    await Deno.writeTextFile(`${folder}/cgroup/lxc.payload.orbit-instances_instance-${id}/memory.swap.max`, "max");
    if (state.marker ?? true) await Deno.writeTextFile(`${folder}/markers/app-instance-${id}.asleep`, "");
    await Deno.writeTextFile(`${folder}/status`, state.status ?? "Frozen");
    await Deno.writeTextFile(`${folder}/web`, (state.web ?? ["200"]).join("\n") + "\n");
    await Deno.writeTextFile(`${folder}/bin/incus`, `#!/bin/sh
echo "$*" >> '${folder}/calls'
case "$1" in
  query) [ "$(cat '${folder}/status')" = Missing ] && exit 1; printf '{"status": "%s", "status_code": 110}' "$(cat '${folder}/status')";;
  --project) ${state.resumeFails ? "exit 1" : `echo Running > '${folder}/status'`};;
  exec) head -n 1 '${folder}/web'; [ "$(wc -l < '${folder}/web')" -gt 1 ] && sed -i 1d '${folder}/web';;
esac
exit 0
`);
    await Deno.chmod(`${folder}/bin/incus`, 0o755);
    return folder;
}

async function run(folder: string) {
    const result = await new Deno.Command("bash", {
        args: [script], stdout: "piped", stderr: "piped",
        env: { PATH: `${folder}/bin:/usr/bin:/bin`, ORBIT_WAKE_REQUESTS: `${folder}/requests`, ORBIT_WAKE_MARKERS: `${folder}/markers`, ORBIT_CGROUP_ROOT: `${folder}/cgroup` },
    }).output();
    assertEquals(result.code, 0);
    return new TextDecoder().decode(result.stdout);
}

const exists = (path: string) => { try { Deno.lstatSync(path); return true; } catch { return false; } };
const calls = (folder: string) => exists(`${folder}/calls`) ? Deno.readTextFileSync(`${folder}/calls`).trim().split("\n") : [];

Deno.test("a request resumes a Frozen container behind its marker, closes swap, waits for FrankenPHP, then removes the marker", async () => {
    const folder = await node("2650", {});
    try {
        await Deno.writeTextFile(`${folder}/requests/app-instance-2650.wake`, "");
        const log = await run(folder);
        assertEquals(calls(folder).slice(0, 2), ["query -- /1.0/instances/instance-2650?project=orbit-instances", "--project orbit-instances resume instance-2650"]);
        assertStringIncludes(calls(folder)[2], "exec --project orbit-instances instance-2650 -- curl");
        assertStringIncludes(calls(folder)[2], "http://127.0.0.1:8080/");
        assertEquals(Deno.readTextFileSync(`${folder}/cgroup/lxc.payload.orbit-instances_instance-2650/memory.swap.max`), "0");
        assert(!exists(`${folder}/markers/app-instance-2650.asleep`), "the marker went");
        assert(!exists(`${folder}/requests/app-instance-2650.wake`), "the request went");
        assert(!exists(`${folder}/requests/app-instance-2650.taken`), "the taken request went at the end");
        assert(exists(`${folder}/requests/app-instance-2650.resumed`), "the Gateway reads this");
        assertStringIncludes(log, "instance 2650: resumed resume_ms=");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("any other state removes only the request and changes nothing", async () => {
    for (const state of [{ status: "Stopped" }, { status: "Running" }, { status: "Missing" }, { marker: false }, { status: "Running", marker: false }] as Node[]) {
        const folder = await node("7", state);
        try {
            await Deno.writeTextFile(`${folder}/requests/app-instance-7.wake`, "");
            await run(folder);
            assert(!exists(`${folder}/requests/app-instance-7.wake`) && !exists(`${folder}/requests/app-instance-7.taken`));
            assert(!exists(`${folder}/requests/app-instance-7.resumed`), JSON.stringify(state));
            assertEquals(exists(`${folder}/markers/app-instance-7.asleep`), state.marker ?? true, JSON.stringify(state));
            assert(calls(folder).every((call) => call.startsWith("query")), `${JSON.stringify(state)}: ${calls(folder)}`);
            assertEquals(Deno.readTextFileSync(`${folder}/cgroup/lxc.payload.orbit-instances_instance-7/memory.swap.max`), "max");
        } finally { await Deno.remove(folder, { recursive: true }); }
    }
});

Deno.test("a request whose name is not app-instance-<digits>.wake, a link, or one older than a second is removed and ignored", async () => {
    const folder = await node("12", {});
    try {
        for (const name of ["app-instance-0.wake", "app-instance-012.wake", "app-instance-12a.wake", "app-instance-1.2.wake", "app-instance-.wake", "app-instance-$(id).wake"]) {
            await Deno.writeTextFile(`${folder}/requests/${name}`, "12");
        }
        await Deno.symlink(`${folder}/markers/app-instance-12.asleep`, `${folder}/requests/app-instance-12.wake`);
        await run(folder);
        assertEquals([...Deno.readDirSync(`${folder}/requests`)].map((entry) => entry.name), []);
        assertEquals(calls(folder), []);

        await Deno.writeTextFile(`${folder}/requests/app-instance-12.wake`, "");
        await Deno.utime(`${folder}/requests/app-instance-12.wake`, new Date(Date.now() - 3000), new Date(Date.now() - 3000));
        const log = await run(folder);
        assertStringIncludes(log, "older than 1 s");
        assertEquals(calls(folder), []);
        assert(exists(`${folder}/markers/app-instance-12.asleep`));
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a silent or 5xx FrankenPHP, or a failed resume, keeps the marker and writes no resumed file", async () => {
    for (const state of [{ web: ["000"] }, { web: ["503"] }, { resumeFails: true }] as Node[]) {
        const folder = await node("9", state);
        try {
            await Deno.writeTextFile(`${folder}/requests/app-instance-9.wake`, "");
            const started = Date.now();
            await run(folder);
            assert(Date.now() - started < 2500, "the probe stops at 1.5 s");
            assert(exists(`${folder}/markers/app-instance-9.asleep`), JSON.stringify(state));
            assert(!exists(`${folder}/requests/app-instance-9.resumed`));
        } finally { await Deno.remove(folder, { recursive: true }); }
    }
});

Deno.test("FrankenPHP that answers after a few tries is waited for", async () => {
    const folder = await node("5", { web: ["000", "000", "302"] });
    try {
        await Deno.writeTextFile(`${folder}/requests/app-instance-5.wake`, "");
        await run(folder);
        assertEquals(calls(folder).filter((call) => call.startsWith("exec")).length, 3);
        assert(!exists(`${folder}/markers/app-instance-5.asleep`));
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the script's folders and port are the Gateway's", () => {
    const text = Deno.readTextFileSync(script);
    const hibernation = gateway("Domain/Hibernation/RuntimeHibernation.php");
    const marker = hibernation.match(/MarkerDirectory = '([^']+)'/)![1];
    const logs = hibernation.match(/AccessLogDirectory = '([^']+)'/)![1];
    const port = gateway("Domain/Instances/InstanceContainer.php").match(/WebPort = (\d+);/)![1];
    assertStringIncludes(text, `requests=\${ORBIT_WAKE_REQUESTS:-${marker}}`);
    assertStringIncludes(text, `markers=\${ORBIT_WAKE_MARKERS:-${logs}}`);
    assertStringIncludes(text, `web_port=${port}\n`);
});

Deno.test("the app-dev role installs the script as root and a watch on the request folder, with no capabilities", () => {
    const tasks = parse(Deno.readTextFileSync(new URL("../ansible/tasks/instance-host.yml", import.meta.url))) as Record<string, any>[];
    const block = tasks.find((task) => task.name === "Install the wake of frozen Instance containers")!.block as Record<string, any>[];
    const copy = (name: string) => block.find((task) => task.name === name)!["ansible.builtin.copy"];
    assertEquals(copy("Write the wake script"), { src: "files/instance-container-wake.sh", dest: "/usr/local/libexec/orbit-instance-wake", owner: "root", group: "root", mode: "0755" });
    const service = copy("Write the wake service").content as string;
    for (const line of ["ExecStart=/usr/local/libexec/orbit-instance-wake", "CapabilityBoundingSet=\n", "NoNewPrivileges=yes", "ProtectSystem=strict", "StartLimitIntervalSec=0"]) {
        assertStringIncludes(service, line);
    }
    assertStringIncludes(copy("Write the wake request watch").content, "PathExistsGlob=/dev/shm/orbit/hibernation/app-instance-*.wake");
    assertEquals(block.at(-1)!["ansible.builtin.systemd_service"], { name: "orbit-instance-wake.path", daemon_reload: "{{ wake_service.changed or wake_path.changed }}", enabled: true, state: "started" });
    const manifest = Deno.readTextFileSync(new URL("../manifest.yaml", import.meta.url));
    assertStringIncludes(manifest, "  - ansible/files/instance-container-wake.sh\n");
});
