/**
 * The Node's fast wake of a frozen Instance container (ansible/files/instance-container-wake.sh): its dispatcher and its worker,
 * run against stubs of systemctl, systemd-run (the power helper's unit), curl, sync and incus.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

const script = fileURLToPath(new URL("../ansible/files/instance-container-wake.sh", import.meta.url));
const gateway = (path: string) => Deno.readTextFileSync(new URL(`../../apps/gateway/app/${path}`, import.meta.url));

type Node = {
    marker?: boolean;
    target?: Record<string, unknown> | null;
    /** The helper's JSON result; null is a busy lock: exit 1 and nothing on stdout. */
    helper?: Record<string, unknown> | null;
    web?: string;
};

const target = (id: number, extra: Record<string, unknown> = {}) => ({ schema_version: 1, instance_id: id, incus_project: "orbit-instances",
    incus_name: `instance-${id}`, incarnation: "c0ffee", web: { address: "10.20.0.5", port: 8080 }, ...extra });
const restored = (id: number, opid: number, status = "complete") => ({ schema_version: 1, command: "restore", instance_id: id, operation_id: opid,
    outcome: "resumed", error_code: null, state_before: "frozen", state_after: "running", prepared_restore: { status } });

/** A Node folder: requests, markers, the helper's state with the target descriptor, and stubs that log each call to `calls`. */
async function node(id: number, opid: number, state: Node = {}) {
    const folder = await Deno.makeTempDir();
    for (const dir of ["bin", "requests", "markers", "state/outcomes", `state/instances/${id}`]) await Deno.mkdir(`${folder}/${dir}`, { recursive: true });
    const marker = `${folder}/markers/app-instance-${id}.asleep`;
    if (state.marker ?? true) await Deno.writeTextFile(marker, "");
    const descriptor = state.target === undefined ? target(id) : state.target;
    if (descriptor !== null) await Deno.writeTextFile(`${folder}/state/instances/${id}/target.json`, JSON.stringify(descriptor));
    const helper = state.helper === undefined ? restored(id, opid) : state.helper;
    const stubs: Record<string, string> = {
        "systemctl": "",
        "systemd-run": helper === null
            ? "echo 'Unit orbit-instance-power-1.service was already loaded or has a fragment file.' >&2; exit 1"
            : `[ -e '${marker}' ] || echo 'no marker at restore' >> '${folder}/calls'; echo '${JSON.stringify(helper)}'`,
        "curl": `[ -e '${marker}' ] || echo 'no marker at readiness' >> '${folder}/calls'; printf '%s' '${state.web ?? "200"}'`,
        // GNU sync fsyncs the files it names; the stub records whether the marker was gone by then.
        "sync": `[ -e '${marker}' ] && echo 'marker at sync' >> '${folder}/calls'; true`,
        "incus": "true",
    };
    for (const [name, body] of Object.entries(stubs)) {
        await Deno.writeTextFile(`${folder}/bin/${name}`, `#!/bin/sh\nprintf '%s\\n' "${name} $*" >> '${folder}/calls'\n${body}\n`);
        await Deno.chmod(`${folder}/bin/${name}`, 0o755);
    }
    return folder;
}

async function run(folder: string, ...args: string[]) {
    const result = await new Deno.Command("bash", {
        args: [script, ...args], stdout: "piped", stderr: "piped",
        env: { PATH: `${folder}/bin:/usr/bin:/bin`, ORBIT_WAKE_REQUESTS: `${folder}/requests`, ORBIT_WAKE_MARKERS: `${folder}/markers`, ORBIT_WAKE_STATE: `${folder}/state` },
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
    return new TextDecoder().decode(result.stdout);
}

const exists = (path: string) => { try { Deno.lstatSync(path); return true; } catch { return false; } };
const calls = (folder: string) => exists(`${folder}/calls`) ? Deno.readTextFileSync(`${folder}/calls`).trim().split("\n") : [];
const outcomes = (folder: string) => [...Deno.readDirSync(`${folder}/state/outcomes`)].map((entry) => entry.name).sort();
const request = (folder: string, id: number, opid: number, age = 0) => {
    const path = `${folder}/requests/app-instance-${id}-op-${opid}.taken`;
    Deno.writeTextFileSync(path, "");
    if (age > 0) Deno.utimeSync(path, new Date(Date.now() - age), new Date(Date.now() - age));
};

Deno.test("the dispatcher renames each operation-qualified request and starts its worker without waiting", async () => {
    const folder = await node(2650, 41);
    try {
        await Deno.writeTextFile(`${folder}/requests/app-instance-2650-op-41.wake`, "");
        await Deno.writeTextFile(`${folder}/requests/app-instance-7-op-9.wake`, "");
        await run(folder, "dispatch");
        assertEquals(calls(folder).sort(), ["systemctl start --no-block orbit-instance-wake@2650-41.service", "systemctl start --no-block orbit-instance-wake@7-9.service"]);
        assertEquals([...Deno.readDirSync(`${folder}/requests`)].map((entry) => entry.name).sort(), ["app-instance-2650-op-41.taken", "app-instance-7-op-9.taken"]);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the dispatcher removes a request with an invalid name or a link, and the old unqualified request starts nothing", async () => {
    const folder = await node(12, 3);
    try {
        for (const name of ["app-instance-0-op-3.wake", "app-instance-012-op-3.wake", "app-instance-12-op-03.wake", "app-instance-12-op-0.wake",
            "app-instance-12a-op-3.wake", "app-instance-1.2-op-3.wake", "app-instance-12-op-3-op-4.wake", "app-instance-$(id)-op-3.wake"]) {
            await Deno.writeTextFile(`${folder}/requests/${name}`, "12");
        }
        await Deno.symlink(`${folder}/markers/app-instance-12.asleep`, `${folder}/requests/app-instance-12-op-3.wake`);
        await Deno.writeTextFile(`${folder}/requests/app-instance-12.wake`, "");
        await run(folder, "dispatch");
        assertEquals([...Deno.readDirSync(`${folder}/requests`)].map((entry) => entry.name), ["app-instance-12.wake"]);
        assertEquals(calls(folder), []);
        assert(exists(`${folder}/markers/app-instance-12.asleep`), "a link's target stays");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a worker restores through the helper's lock, checks readiness from the Node, removes the marker, then writes ready and done", async () => {
    const folder = await node(2650, 41);
    try {
        request(folder, 2650, 41);
        const log = await run(folder, "work", "2650-41");
        const made = calls(folder);
        assertEquals(made[0], "systemd-run --unit=orbit-instance-power-2650 --wait --pipe --collect --quiet /usr/local/libexec/orbit-instance-power restore --instance 2650 --operation 41");
        assertEquals(made[1], "curl --silent --output /dev/null --write-out %{http_code} --max-time 1.5 http://10.20.0.5:8080/");
        assert(!made.includes("no marker at restore") && !made.includes("no marker at readiness"), "restore and readiness come before the marker goes");
        assert(!made.includes("marker at sync"), "the marker is gone before an outcome is written");
        const outcome = `${folder}/state/outcomes/app-instance-2650-op-41`;
        assertEquals(made.slice(2, 4), [`sync -- ${outcome}.ready-complete ${folder}/state/outcomes`, `sync -- ${outcome}.done ${folder}/state/outcomes`]);
        assertEquals(outcomes(folder), ["app-instance-2650-op-41.done", "app-instance-2650-op-41.ready-complete"]);
        assertEquals(Deno.statSync(`${outcome}.ready-complete`).mode! & 0o777, 0o644);
        assert(!exists(`${folder}/markers/app-instance-2650.asleep`));
        assert(made.slice(4).every((call) => call.startsWith("incus exec --project orbit-instances instance-2650 -- sh -c")), "maintenance comes after done");
        assertStringIncludes(log, "instance 2650 op 41: resumed restore=complete restore_ms=");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the ready file names the helper's restore status; a container without a web unit gets no request", async () => {
    for (const [status, file] of [["partial", "ready-partial"], ["skipped", "ready-skipped"], ["timed_out", "ready-timed-out"]]) {
        const folder = await node(5, 8, { helper: restored(5, 8, status), target: target(5, { web: null }) });
        try {
            request(folder, 5, 8);
            await run(folder, "work", "5-8");
            assertEquals(outcomes(folder), ["app-instance-5-op-8.done", `app-instance-5-op-8.${file}`]);
            assert(!calls(folder).some((call) => call.startsWith("curl")), status);
        } finally { await Deno.remove(folder, { recursive: true }); }
    }
});

Deno.test("a worker declines with failed and keeps the marker, writing no ready or done file it did not earn", async () => {
    const cases: [string, Node, number, boolean][] = [
        ["request older than 1 s", {}, 3000, false],
        ["no asleep marker", { marker: false }, 0, false],
        ["no target descriptor", { target: null }, 0, false],
        ["another Instance's descriptor", { target: target(99) }, 0, false],
        ["a project the helper does not know", { target: target(9, { incus_project: "default" }) }, 0, false],
        ["a nested-fleet Instance", { target: target(9, { incus_project: "orbit-instances-nested" }) }, 0, false],
        ["a busy lock", { helper: null }, 0, true],
        ["a container that already ran", { helper: { ...restored(9, 4), outcome: "already_running", prepared_restore: { status: "not_applicable" } } }, 0, true],
        ["a restore that failed", { helper: { ...restored(9, 4), outcome: "failed", error_code: "instance.container_start_failed" } }, 0, true],
        ["a 5xx web unit", { web: "503" }, 0, true],
        ["a silent web unit", { web: "000" }, 0, true],
    ];
    for (const [name, state, age, helped] of cases) {
        const folder = await node(9, 4, state);
        try {
            request(folder, 9, 4, age);
            await run(folder, "work", "9-4");
            assertEquals(outcomes(folder), ["app-instance-9-op-4.failed"], name);
            assertEquals(exists(`${folder}/markers/app-instance-9.asleep`), state.marker ?? true, name);
            assertEquals(calls(folder).some((call) => call.startsWith("systemd-run")), helped, name);
        } finally { await Deno.remove(folder, { recursive: true }); }
    }
});

Deno.test("a worker whose Operation has an outcome repeats nothing and keeps its times; another Operation's outcome does not count", async () => {
    const folder = await node(3, 10);
    try {
        const old = new Date(Date.now() - 60_000);
        const outcome = (name: string) => {
            Deno.writeTextFileSync(`${folder}/state/outcomes/${name}`, "");
            Deno.utimeSync(`${folder}/state/outcomes/${name}`, old, old);
        };
        const mtime = (name: string) => Deno.statSync(`${folder}/state/outcomes/${name}`).mtime!.getTime();
        outcome("app-instance-3-op-10.ready-partial");
        outcome("app-instance-3-op-10.done");
        request(folder, 3, 10);
        await run(folder, "work", "3-10");
        assertEquals(calls(folder), []);
        assertEquals(mtime("app-instance-3-op-10.ready-partial"), old.getTime());

        // A worker that ended after its ready file: only done is added, and the ready time stays.
        Deno.removeSync(`${folder}/state/outcomes/app-instance-3-op-10.done`);
        await run(folder, "work", "3-10");
        assertEquals(calls(folder).filter((call) => !call.includes("sync")), []);
        assert(exists(`${folder}/state/outcomes/app-instance-3-op-10.done`));
        assertEquals(mtime("app-instance-3-op-10.ready-partial"), old.getTime());

        outcome("app-instance-3-op-11.failed");
        await run(folder, "work", "3-11");
        assertEquals(calls(folder).filter((call) => !call.includes("sync")), []);

        // Operation 12 finds only the outcomes of 10 and 11: it wakes for itself.
        request(folder, 3, 12);
        await run(folder, "work", "3-12");
        assert(calls(folder).some((call) => call.endsWith("restore --instance 3 --operation 12")));
        assert(exists(`${folder}/state/outcomes/app-instance-3-op-12.ready-complete`));
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("a worker with an invalid unit argument does nothing", async () => {
    const folder = await node(4, 2);
    try {
        request(folder, 4, 2);
        for (const argument of ["0-2", "4-02", "4", "4-2-3", "../4-2", "4-2 ", ""]) await run(folder, "work", argument);
        assertEquals(calls(folder), []);
        assertEquals(outcomes(folder), []);
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the script holds no wait loop and no lock of its own (shape gate)", () => {
    const text = Deno.readTextFileSync(script);
    for (const pattern of [/\bsleep\s/, /while\s+true/, /\bflock\b/, /\blockfile\b/, /\bwhile\b/]) assert(!text.match(pattern), String(pattern));
});

Deno.test("the script's folders and files are the Gateway's", () => {
    const text = Deno.readTextFileSync(script);
    const hibernation = gateway("Domain/Hibernation/RuntimeHibernation.php");
    const marker = hibernation.match(/MarkerDirectory = '([^']+)'/)![1];
    const logs = hibernation.match(/AccessLogDirectory = '([^']+)'/)![1];
    assertStringIncludes(text, `requests=\${ORBIT_WAKE_REQUESTS:-${marker}}`);
    assertStringIncludes(text, `markers=\${ORBIT_WAKE_MARKERS:-${logs}}`);
    const resume = gateway("Domain/Instances/Power/ContainerResume.php");
    const outcomes = resume.match(/OutcomeDirectory = '([^']+)'/)![1];
    assertEquals(outcomes, "/var/lib/orbit/hibernation/outcomes");
    assertStringIncludes(text, "state=${ORBIT_WAKE_STATE:-/var/lib/orbit/hibernation}\noutcomes=$state/outcomes\n");
    for (const status of ["complete", "partial", "skipped", "timed-out"]) assertStringIncludes(resume, `'${status}'`);
    // The Gateway tells a Node with the fast wake by the worker template, which the role writes.
    const worker = resume.match(/WorkerPath = '([^']+)'/)![1];
    assertStringIncludes(Deno.readTextFileSync(new URL("../ansible/tasks/instance-host.yml", import.meta.url)), `dest: ${worker}\n`);
});

Deno.test("the app-dev role installs the script, a dispatcher, a worker template and a watch on qualified requests, with no capabilities", () => {
    const tasks = parse(Deno.readTextFileSync(new URL("../ansible/tasks/instance-host.yml", import.meta.url))) as Record<string, any>[];
    const block = tasks.find((task) => task.name === "Install the wake of frozen Instance containers")!.block as Record<string, any>[];
    const copy = (name: string) => block.find((task) => task.name === name)!["ansible.builtin.copy"];
    assertEquals(copy("Write the wake script"), { src: "files/instance-container-wake.sh", dest: "/usr/local/libexec/orbit-instance-wake", owner: "root", group: "root", mode: "0755" });
    assertEquals(block.find((task) => task.name === "Make the folder of the wake outcomes")!["ansible.builtin.file"],
        { path: "/var/lib/orbit/hibernation/outcomes", state: "directory", owner: "root", group: "caddy", mode: "0755" });
    const dispatcher = copy("Write the wake dispatcher").content as string;
    const worker = copy("Write the wake worker").content as string;
    for (const [unit, start] of [[dispatcher, "orbit-instance-wake dispatch"], [worker, "orbit-instance-wake work %i"]]) {
        for (const line of [`ExecStart=/usr/local/libexec/${start}\n`, "CapabilityBoundingSet=\n", "NoNewPrivileges=yes", "ProtectSystem=strict", "StartLimitIntervalSec=0"]) {
            assertStringIncludes(unit, line);
        }
    }
    assertEquals(copy("Write the wake worker").dest, "/etc/systemd/system/orbit-instance-wake@.service");
    assertStringIncludes(worker, " /var/lib/orbit/hibernation/outcomes\n");
    assertStringIncludes(copy("Write the wake request watch").content, "PathExistsGlob=/dev/shm/orbit/hibernation/app-instance-*-op-*.wake\n");
    assertEquals(block.at(-1)!["ansible.builtin.systemd_service"].name, "orbit-instance-wake.path");
    const manifest = Deno.readTextFileSync(new URL("../manifest.yaml", import.meta.url));
    assertStringIncludes(manifest, "  - ansible/files/instance-container-wake.sh\n");
});

const libraryCheck = (text: string) => text.match(/d=\/opt\/orbit-runtime\/current\/lib;[^\n']*done/)![0];

Deno.test("the Node script and the wake's workflow run the same library check, after the marker went", () => {
    const power = Deno.readTextFileSync(new URL("../ansible/tasks/instance-container-power.yml", import.meta.url));
    assertEquals(libraryCheck(Deno.readTextFileSync(script)), libraryCheck(power));
    const tasks = parse(power) as Record<string, any>[];
    const names = tasks.map((task) => task.name);
    const refresh = names.indexOf("Refresh a stale library cache after the wake");
    assert(refresh > names.indexOf("Remove the asleep marker") && refresh < names.indexOf("Wait for T3"), names.join(" | "));
    assertEquals(tasks[refresh].failed_when, false);
    // In the script the check comes after the marker went and the done file exists.
    const text = Deno.readTextFileSync(script);
    assert(text.indexOf('sh -c "$library_check"') > text.indexOf('publish "$base.done"'));
});

Deno.test("the library check rebuilds the cache only when a soname of the runtime folder is missing at that path, as the converge reads it", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/lib`);
        await Deno.mkdir(`${folder}/bin`);
        // A real folder holds the file with its full version, its soname link and a dev link; the cache lists only the last two.
        for (const name of ["libnss3.so", "libXRes.so.1.0.0", "libasound.so.2.0.0", "libgraphite2.so.3.2.1"]) await Deno.writeTextFile(`${folder}/lib/${name}`, "");
        await Deno.symlink("libXRes.so.1.0.0", `${folder}/lib/libXRes.so.1`);
        await Deno.symlink("libasound.so.2.0.0", `${folder}/lib/libasound.so.2`);
        await Deno.symlink("libgraphite2.so.3.2.1", `${folder}/lib/libgraphite2.so.3`);
        const lib = `${folder}/lib`;
        const check = libraryCheck(Deno.readTextFileSync(script)).replace("/opt/orbit-runtime/current/lib", lib);
        const run = async (cache: string[]) => {
            try { Deno.removeSync(`${folder}/rebuilt`); } catch { /* none yet */ }
            await Deno.writeTextFile(`${folder}/cache`, cache.join("\n") + "\n");
            await Deno.writeTextFile(`${folder}/bin/ldconfig`, `#!/bin/sh\nif [ "$1" = -p ]; then cat '${folder}/cache'; else echo "$*" >> '${folder}/rebuilt'; fi\n`);
            await Deno.chmod(`${folder}/bin/ldconfig`, 0o755);
            const out = await new Deno.Command("sh", { args: ["-c", check], stdout: "piped", env: { PATH: `${folder}/bin:/usr/bin:/bin` } }).output();
            return new TextDecoder().decode(out.stdout).trim();
        };
        const entry = (name: string, at = lib) => `\t${name} (libc6,x86-64) => ${at}/${name}`;
        const complete = ["2 libs found in cache `/etc/ld.so.cache'", entry("libnss3.so"), entry("libXRes.so.1"), entry("libasound.so.2"), entry("libgraphite2.so.3"),
            entry("libc.so.6", "/lib/x86_64-linux-gnu")];
        assertEquals(await run(complete), "", "a complete cache is left alone");
        assert(!exists(`${folder}/rebuilt`));
        assertEquals(await run(complete.filter((line) => !line.includes("libXRes.so.1 "))), "rebuilt", "a missing soname rebuilds it");
        assertEquals(Deno.readTextFileSync(`${folder}/rebuilt`).trim(), "-X");
        assertEquals(await run([...complete.filter((line) => !line.includes("libasound")), entry("libasound.so.2", "/usr/lib")]), "rebuilt",
            "a soname that resolves elsewhere rebuilds it");
        assertEquals(await run([...complete.filter((line) => !line.includes("libasound.so.2 ")), entry("libasound.so.20")]), "rebuilt",
            "a longer name that starts with the soname is not the soname");
        Deno.removeSync(lib, { recursive: true });
        assertEquals(await run(complete), "", "no runtime folder: nothing to check");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

const caCheck = (text: string) => text.match(/r=\/usr\/local\/share\/ca-certificates\/orbit-managed-root-ca\.crt;[^\n']*refreshed; \}/)![0];

Deno.test("the Node script and the wake's workflow run the same CA bundle check, after the library check", () => {
    const power = Deno.readTextFileSync(new URL("../ansible/tasks/instance-container-power.yml", import.meta.url));
    const text = Deno.readTextFileSync(script);
    assertEquals(caCheck(text), caCheck(power));
    const tasks = parse(power) as Record<string, any>[];
    const names = tasks.map((task) => task.name);
    const ca = names.indexOf("Refresh a stale CA bundle after the wake");
    assert(ca === names.indexOf("Refresh a stale library cache after the wake") + 1 && ca < names.indexOf("Wait for T3"), names.join(" | "));
    assertEquals(tasks[ca].failed_when, false);
    assertEquals(tasks[ca].changed_when, "'refreshed' in wake_ca_bundle.stdout");
    assert(text.indexOf('sh -c "$ca_check"') > text.indexOf('sh -c "$library_check"'));
});

Deno.test("the CA bundle check refreshes the bundle only when it lacks the container's Orbit root, and skips a container without it", async () => {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/bin`);
        const root = `${folder}/orbit-managed-root-ca.crt`;
        const bundle = `${folder}/ca-certificates.crt`;
        const pem = (body: string) => `-----BEGIN CERTIFICATE-----\n${body.match(/.{1,64}/g)!.join("\n")}\n-----END CERTIFICATE-----\n`;
        const oldRoot = "MIIB" + "A".repeat(120);
        const newRoot = "MIIB" + "B".repeat(120);
        // update-ca-certificates appends the root, as the real one does from the CA folder.
        await Deno.writeTextFile(`${folder}/bin/update-ca-certificates`, `#!/bin/sh\ncat '${root}' >> '${bundle}'\necho 'Updating certificates'\n`);
        await Deno.chmod(`${folder}/bin/update-ca-certificates`, 0o755);
        const check = caCheck(Deno.readTextFileSync(script)).replace("/usr/local/share/ca-certificates/orbit-managed-root-ca.crt", root)
            .replace("/etc/ssl/certs/ca-certificates.crt", bundle);
        const run = async () => {
            const out = await new Deno.Command("sh", { args: ["-c", check], stdout: "piped", stderr: "piped", env: { PATH: `${folder}/bin:/usr/bin:/bin` } }).output();
            assertEquals(out.code, 0);
            return new TextDecoder().decode(out.stdout).trim();
        };
        await Deno.writeTextFile(bundle, pem("MIIC" + "Z".repeat(200)) + pem(oldRoot));
        await Deno.writeTextFile(root, pem(newRoot));
        assertEquals(await run(), "refreshed", "a rotated root that the bundle lacks refreshes it");
        assertStringIncludes(Deno.readTextFileSync(bundle).replace(/\n/g, ""), newRoot);
        assertEquals(await run(), "", "a second wake changes nothing");
        Deno.removeSync(root);
        assertEquals(await run(), "", "a container without the CA file skips the check");
    } finally { await Deno.remove(folder, { recursive: true }); }
});

Deno.test("the worker and the wake's run send the same readiness request from the Node, and no run asks the web port through incus exec", () => {
    const flags = (text: string) => text.replaceAll("'", "").match(/--silent --output \/dev\/null --write-out \S+ --max-time [0-9.]+/)![0];
    const worker = Deno.readTextFileSync(script).match(/curl (--silent[^\n]*?) "http:\/\/\$address:\$port\/"/)![1];
    const plays = parse(Deno.readTextFileSync(new URL("../ansible/instance-container.yml", import.meta.url))) as Record<string, any>[];
    const probe = (plays.find((play) => play.vars?.web_probe)!.vars.web_probe as string).replace(/[\[\],]/g, " ").replace(/\s+/g, " ");
    assertEquals(flags(worker), flags(probe));
    assertStringIncludes(probe, "'http://' ~ container.address ~ ':' ~ container.web_port ~ '/'");
    for (const file of ["instance-container-power.yml", "instance-container-phase.yml"]) {
        const text = Deno.readTextFileSync(new URL(`../ansible/tasks/${file}`, import.meta.url));
        assert(!text.match(/127\.0\.0\.1:' ~ intent\.web\.port/), file);
    }
});
