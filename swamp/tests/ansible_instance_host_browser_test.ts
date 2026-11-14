/**
 * The runtime folder's browser libraries and fonts, the image's shared files in every existing container, and Chromium's trust of
 * the Orbit root CA (docs/reference/development-containers.md#browser-libraries-and-fonts, #existing-containers, #trust).
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { browserTrust, fixture, hasAnsible } from "./_support/instance_host.ts";

const sha256 = async (path: string) =>
    Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", Deno.readFileSync(path))))
        .map((b) => b.toString(16).padStart(2, "0")).join("");

/** A Debian package with the given files, built with the real dpkg-deb, as a runtime part of the fixture. */
async function deb(folder: string, name: string, files: Record<string, string>) {
    const work = Deno.makeTempDirSync();
    Deno.mkdirSync(`${work}/DEBIAN`);
    Deno.writeTextFileSync(`${work}/DEBIAN/control`, `Package: ${name}\nVersion: 1.0\nArchitecture: amd64\nMaintainer: fixture\nDescription: fixture\n`);
    for (const [path, content] of Object.entries(files)) {
        Deno.mkdirSync(`${work}/${path.split("/").slice(0, -1).join("/")}`, { recursive: true });
        Deno.writeTextFileSync(`${work}/${path}`, content);
    }
    const built = new Deno.Command("dpkg-deb", { args: ["--root-owner-group", "-b", work, `${folder}/artifacts/${name}_1.0_amd64.deb`] }).outputSync();
    assert(built.success, new TextDecoder().decode(built.stderr));
    Deno.removeSync(work, { recursive: true });
    return {
        name: `browser-${name}`, version: "1.0", url: `https://example.test/pool/${name}_1.0_amd64.deb`, archive: "deb",
        sha256: await sha256(`${folder}/artifacts/${name}_1.0_amd64.deb`), bin: {},
    };
}

const shared = [
    { path: "/etc/ld.so.conf.d/zz-orbit-browser.conf", content: "# Managed by Orbit.\n/opt/orbit-runtime/current/lib\n", uid: 0, gid: 0, mode: "0644", existing: true },
    { path: "/etc/fonts/fonts.conf", content: "<fontconfig><dir>/opt/orbit-runtime/current/fonts</dir></fontconfig>\n", uid: 0, gid: 0, mode: "0644", existing: true },
    { path: "/etc/gitconfig", content: "# Managed by Orbit.\n[safe]\n\tdirectory = /srv/workspace\n", uid: 0, gid: 0, mode: "0644", existing: true },
];

Deno.test({
    name: "a Debian package part gives the runtime folder its libraries and fonts; existing containers, frozen ones too, get the shared files with no restart",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const nss = await deb(f.folder, "libfake3", {
                "usr/lib/x86_64-linux-gnu/libfake3.so": "library",
                "usr/lib/x86_64-linux-gnu/libfakeutil.so.1.0.0": "versioned",
                "usr/lib/x86_64-linux-gnu/libfakeutil.so.1": "soname",
                "usr/lib/x86_64-linux-gnu/nss/libsoftfake.so": "module",
                "usr/share/doc/libfake3/copyright": "doc",
            });
            const font = await deb(f.folder, "fonts-fake", { "usr/share/fonts/truetype/fake/FakeSans.ttf": "font" });
            const state = f.state();
            state.instances.push(
                { name: "app-default", project: "orbit-instances", status: "Running" },
                { name: "app-asleep", project: "orbit-instances", status: "Stopped" },
                { name: "app-frozen", project: "orbit-instances", status: "Frozen" },
            );
            state.dirs = homes(["app-default", "app-asleep", "app-frozen"]);
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            f.write({
                ...f.instances,
                image: { ...f.instances.image, files: [...f.instances.image.files, ...shared] },
                folders: { ...f.instances.folders, runtime: { ...f.instances.folders.runtime, parts: [...f.instances.folders.runtime.parts, nss, font] } },
            });
            const result = await f.run();
            assert(result.ok, result.output);

            const version = `${f.folder}/opt/orbit-runtime/111111111111`;
            assertEquals([...Deno.readDirSync(`${version}/lib`)].map((e) => e.name).sort(), ["libfake3.so", "libfakeutil.so.1", "libfakeutil.so.1.0.0", "nss"]);
            // Relative links into the part, so they survive the rename of the staging folder; the NSS module folder comes along.
            assertEquals(Deno.readLinkSync(`${version}/lib/libfake3.so`), "../browser-libfake3/usr/lib/x86_64-linux-gnu/libfake3.so");
            assertEquals(Deno.readTextFileSync(`${version}/lib/nss/libsoftfake.so`), "module");
            assertEquals(Deno.readTextFileSync(`${version}/fonts/FakeSans.ttf`), "font");
            // The trust module's package gives only its module, outside `lib`: no `pkcs11` folder and no `p11-kit-proxy.so` there.
            assertEquals([...Deno.readDirSync(`${version}/pkcs11`)].map((e) => e.name).sort(), ["p11-kit-trust.so", "requires"]);
            assertEquals(Deno.readTextFileSync(`${version}/pkcs11/requires`), "libp11-kit0 0.26.2-2\n");
            assertEquals(Deno.readLinkSync(`${version}/pkcs11/p11-kit-trust.so`), "../browser-p11-kit-modules/usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-trust.so");
            assertEquals(Deno.readTextFileSync(`${f.folder}/opt/orbit-runtime/current/pkcs11/p11-kit-trust.so`), "trust module");

            const after = f.state();
            // Every running and stopped container gets each shared file; a frozen one is left alone.
            // Running, stopped and frozen containers all get each shared file through the file API.
            for (const name of ["app-default", "app-asleep", "app-frozen"]) {
                for (const file of shared) {
                    assertEquals(after.files[`${name}${file.path}`], { content: file.content, mode: "0644", uid: "0", gid: "0" });
                }
            }
            // `agent` writes its NSS store in the running container; a stopped or frozen one gets it at a converge that finds it running.
            assertEquals(after.files[`app-default${browserTrust.path}`], { content: browserTrust.entry, mode: "0600", uid: "2750", gid: "2750" });
            for (const name of ["app-asleep", "app-frozen"]) assert(!after.files[`${name}${browserTrust.path}`], name);
            // A frozen container gets no exec, and it keeps its cache: it thaws without a boot, and its wake runs the check.
            assert(!f.calls().some((call) => call[0] === "exec" && call.includes("app-frozen")));
            assert(!(after.deleted_files ?? []).includes("app-frozen/etc/ld.so.cache"));
            // The running container reads the new libraries into its cache; the stopped one loses its cache, which the stock
            // `ldconfig.service` builds again at its next start.
            assertEquals(after.ldcache["app-default"], ["libfake3.so", "libfakeutil.so.1"]);
            assertEquals(after.deleted_files.filter((path: string) => path.endsWith("ld.so.cache")), ["app-asleep/etc/ld.so.cache"]);
            // Nothing restarts: no T3, no Process.
            assert(!f.calls().some((call) => call.includes("try-restart") || call.includes("restart")), JSON.stringify(f.calls()));

        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "two Debian packages that give the same library name fail the folder build: one would hide the other",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const one = await deb(f.folder, "libone", { "usr/lib/x86_64-linux-gnu/libsame.so.1": "one" });
            const two = await deb(f.folder, "libtwo", { "usr/lib/x86_64-linux-gnu/libsame.so.1": "two" });
            f.write({ ...f.instances, folders: { ...f.instances.folders, runtime: { ...f.instances.folders.runtime, parts: [...f.instances.folders.runtime.parts, one, two] } } });
            const result = await f.run();
            assert(!result.ok);
            assertStringIncludes(result.output, "lib/libsame.so.1");
            assert(!f.has("opt/orbit-runtime/111111111111"));
        } finally {
            f.cleanup();
        }
    },
});

/** A home of `agent` in each named container, as the image makes it. */
const homes = (names: string[]) => Object.fromEntries(names.map((name) => [`${name}/home/agent`, { mode: "0700", uid: "2750", gid: "2750" }]));

/** A bare Incus boundary of containers, for the container step's own script; `extra` goes into the fake's state. */
function containers(rows: { name: string; status: string }[], extra: Record<string, unknown> = {}) {
    const folder = Deno.makeTempDirSync();
    Deno.mkdirSync(`${folder}/bin`);
    Deno.writeTextFileSync(`${folder}/bin/incus`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/task-vm-host-incus.ts", import.meta.url).pathname}' "$@"\n`);
    Deno.chmodSync(`${folder}/bin/incus`, 0o755);
    Deno.writeTextFileSync(`${folder}/calls`, "");
    new Deno.Command("openssl", { args: ["req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2", "-subj", "/CN=Orbit Fixture Root",
        "-keyout", `${folder}/orbit-root-ca.key`, "-out", `${folder}/orbit-root-ca.crt`] }).outputSync();
    Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({ instances: rows.map((row) => ({ ...row, project: "orbit-instances" })), ...extra }));
    return { folder, state: () => JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)),
        calls: () => Deno.readTextFileSync(`${folder}/calls`).trim().split("\n").filter(Boolean).map((line) => JSON.parse(line) as string[]),
        cleanup: () => Deno.removeSync(folder, { recursive: true }) };
}

/** The container step's own script against the fixture's Incus, without a whole converge; `root` is the Node's CA file. */
function pass(folder: string, root = `${folder}/orbit-root-ca.crt`) {
    const out = new Deno.Command("python3", {
        args: ["-I", new URL("../ansible/instance_container_files.py", import.meta.url).pathname, JSON.stringify({
            projects: ["orbit-instances", "orbit-instances-nested"], files: shared.map(({ path, content, mode }) => ({ path, content, mode })),
            trust: { source: root, path: "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt", directory: "/etc/ssl/certs" },
            browser_trust: { ...browserTrust, script: Deno.readTextFileSync(new URL("../ansible/files/instance-browser-trust.sh", import.meta.url)) },
            libraries: `${folder}/opt/orbit-runtime/current/lib`, state: `${folder}/container-libraries`,
        })],
        env: { HOST_FIXTURE: folder, PATH: `${folder}/bin:${Deno.env.get("PATH")}` },
    }).outputSync();
    return { ok: out.success, summary: JSON.parse(new TextDecoder().decode(out.stdout)) };
}

Deno.test({
    name: "a second pass writes nothing and runs no ldconfig; a new library reaches running and stopped containers alike",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const lib = `${f.folder}/opt/orbit-runtime/current/lib`;
            Deno.mkdirSync(lib, { recursive: true });
            Deno.writeTextFileSync(`${lib}/libone.so.1`, "one");
            const state = f.state();
            state.instances.push(
                { name: "app-default", project: "orbit-instances", status: "Running" },
                { name: "app-asleep", project: "orbit-instances", status: "Stopped" },
                { name: "fleet-trial", project: "orbit-instances-nested", status: "Running" },
            );
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            let run = pass(f.folder);
            assert(run.ok, JSON.stringify(run.summary));
            // The nested-fleet project's containers mount the same runtime folder, so they get the same files: 3 shared files, the
            // Node's root CA and its two links (`<name>.pem` and the hashed name), in each of the 3 containers.
            assertEquals(run.summary.written.length, 18);
            assertEquals(run.summary.ca_bundle, ["orbit-instances/app-default", "orbit-instances-nested/fleet-trial"]);
            assert(run.summary.written.includes("orbit-instances-nested/fleet-trial/etc/gitconfig"));
            assertEquals(run.summary.ldconfig, ["orbit-instances/app-default", "orbit-instances-nested/fleet-trial"]);
            assertEquals(run.summary.cache_dropped, ["orbit-instances/app-asleep"]);

            run = pass(f.folder);
            assert(run.ok, JSON.stringify(run.summary));
            assertEquals([run.summary.written, run.summary.ldconfig, run.summary.cache_dropped, run.summary.ca_bundle], [[], [], [], []]);

            // A new library in the folder: the running cache lacks it, and the stopped container builds its cache again at start.
            Deno.writeTextFileSync(`${lib}/libtwo.so.2`, "two");
            run = pass(f.folder);
            assert(run.ok, JSON.stringify(run.summary));
            assertEquals([run.summary.written, run.summary.ldconfig, run.summary.cache_dropped],
                [[], ["orbit-instances/app-default", "orbit-instances-nested/fleet-trial"], ["orbit-instances/app-asleep"]]);
            assertEquals(f.state().ldcache["app-default"], ["libone.so.1", "libtwo.so.2"]);
        } finally {
            f.cleanup();
        }
    },
});

/** The restart rule's own script: whether the new folder version keeps everything the version in use holds. */
function same(old: string, next: string) {
    const out = new Deno.Command("python3", { args: ["-I", new URL("../ansible/instance_folder_same.py", import.meta.url).pathname, old, next] }).outputSync();
    assert(out.success, new TextDecoder().decode(out.stderr));
    return JSON.parse(new TextDecoder().decode(out.stdout)).same as boolean;
}

Deno.test({
    name: "a new folder version that only adds files keeps every running process valid; a changed, missing or relinked file does not",
    fn() {
        const root = Deno.makeTempDirSync();
        try {
            const version = (name: string) => {
                const dir = `${root}/${name}`;
                Deno.mkdirSync(`${dir}/php/8.5/bin`, { recursive: true });
                Deno.mkdirSync(`${dir}/bin`);
                Deno.writeTextFileSync(`${dir}/php/8.5/bin/php`, "php 8.5.1");
                Deno.chmodSync(`${dir}/php/8.5/bin/php`, 0o755);
                Deno.symlinkSync("../php/8.5/bin/php", `${dir}/bin/php`);
                return dir;
            };
            const old = version("old");
            const added = version("added");
            Deno.mkdirSync(`${added}/lib`);
            Deno.writeTextFileSync(`${added}/lib/libnss3.so`, "nss");
            Deno.symlinkSync("../bun/bun", `${added}/bin/bunx`);
            assert(same(old, added), "a browser part and a new program link restart nothing");

            const changed = version("changed");
            Deno.writeTextFileSync(`${changed}/php/8.5/bin/php`, "php 8.5.2");
            assert(!same(old, changed), "a new PHP build restarts");
            const relinked = version("relinked");
            Deno.removeSync(`${relinked}/bin/php`);
            Deno.symlinkSync("../php/8.6/bin/php", `${relinked}/bin/php`);
            assert(!same(old, relinked), "a program that points elsewhere restarts");
            const missing = version("missing");
            Deno.removeSync(`${missing}/bin/php`);
            assert(!same(old, missing), "a removed program restarts");
            const mode = version("mode");
            Deno.chmodSync(`${mode}/php/8.5/bin/php`, 0o644);
            assert(!same(old, mode), "a changed mode restarts");
            assert(!same(`${root}/gone`, added), "a version in use that is gone restarts, as before");
        } finally {
            Deno.removeSync(root, { recursive: true });
        }
    },
});

Deno.test({
    name: "a converge that adds browser parts to a runtime folder in use flips it and restarts no Process in a running container",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            let result = await f.run();
            assert(result.ok, result.output);
            const state = f.state();
            state.instances.push({ name: "app-default", project: "orbit-instances", status: "Running" });
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            const nss = await deb(f.folder, "libfake3", { "usr/lib/x86_64-linux-gnu/libfake3.so": "library" });
            f.write({
                ...f.instances,
                image: { ...f.instances.image, files: [...f.instances.image.files, ...shared] },
                folders: { ...f.instances.folders, runtime: { ...f.instances.folders.runtime, version: "444444444444",
                    parts: [...f.instances.folders.runtime.parts, nss] } },
            });
            const before = f.calls().length;
            result = await f.run();
            assert(result.ok, result.output);
            assertEquals(Deno.readLinkSync(`${f.folder}/opt/orbit-runtime/current`), "444444444444");
            const calls = f.calls().slice(before);
            assert(!calls.some((call) => call.includes("try-restart")), JSON.stringify(calls.filter((call) => call.includes("exec"))));
            assert(!f.has("opt/orbit-runtime/.restart"));
            assertEquals(f.state().ldcache["app-default"], ["libfake3.so"]);
            // The old version goes once no process maps it, as before.
            assert(!f.has("opt/orbit-runtime/111111111111"));
        } finally {
            f.cleanup();
        }
    },
});

/** A self-signed root with its OpenSSL hashed name. */
function root(folder: string, name: string) {
    const made = new Deno.Command("openssl", { args: ["req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2", "-subj", `/CN=${name}`,
        "-keyout", `${folder}/${name}.key`, "-out", `${folder}/${name}.crt`] }).outputSync();
    assert(made.success, new TextDecoder().decode(made.stderr));
    const digest = new Deno.Command("openssl", { args: ["x509", "-noout", "-subject_hash", "-in", `${folder}/${name}.crt`] }).outputSync();
    return { path: `${folder}/${name}.crt`, pem: Deno.readTextFileSync(`${folder}/${name}.crt`), hash: new TextDecoder().decode(digest.stdout).trim() };
}

Deno.test({
    name: "a rotated Orbit root replaces the old one and its hashed name in running, stopped and frozen containers; a frozen one gets no exec",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const old = root(f.folder, "old-root");
            const next = root(f.folder, "new-root");
            const ca = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt";
            const state = f.state();
            state.instances.push(
                { name: "app-default", project: "orbit-instances", status: "Running" },
                { name: "app-asleep", project: "orbit-instances", status: "Stopped" },
                { name: "app-frozen", project: "orbit-instances", status: "Frozen" },
            );
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            let run = pass(f.folder, old.path);
            assert(run.ok, JSON.stringify(run.summary));
            assertEquals(run.summary.frozen, ["orbit-instances/app-frozen"]);
            const linked = (name: string, hash: string) =>
                [`/etc/ssl/certs/orbit-managed-root-ca.pem`, "orbit-managed-root-ca.pem"].includes(f.state().links?.[`${name}/etc/ssl/certs/${hash}.0`]);
            for (const name of ["app-default", "app-asleep", "app-frozen"]) {
                assertEquals(f.state().files[`${name}${ca}`].content, old.pem);
                assert(linked(name, old.hash), name);
            }

            const before = f.calls().length;
            run = pass(f.folder, next.path);
            assert(run.ok, JSON.stringify(run.summary));
            const files = f.state().files;
            for (const name of ["app-default", "app-asleep", "app-frozen"]) {
                assertEquals(files[`${name}${ca}`].content, next.pem);
                assert(linked(name, next.hash), name);
                assert(!f.state().links?.[`${name}/etc/ssl/certs/${old.hash}.0`], name);
                assert(run.summary.removed.includes(`orbit-instances/${name}/etc/ssl/certs/${old.hash}.0`), JSON.stringify(run.summary));
            }
            assertEquals(f.state().deleted_files.filter((path: string) => path.endsWith(`${old.hash}.0`)).sort(),
                ["app-asleep", "app-default", "app-frozen"].map((name) => `${name}/etc/ssl/certs/${old.hash}.0`));
            // Only the running container reads its bundle again; the frozen one gets file writes and deletes only, never an exec.
            assertEquals(run.summary.ca_bundle, ["orbit-instances/app-default"]);
            assert(!f.calls().slice(before).some((call) => call[0] === "exec" && call.includes("app-frozen")));
            assertEquals(files["app-default/etc/ssl/certs/ca-certificates.crt"].content, next.pem);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a Node without a readable Orbit root CA fails the step by name and writes nothing",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            const state = f.state();
            state.instances.push({ name: "app-default", project: "orbit-instances", status: "Running" });
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            const run = pass(f.folder, `${f.folder}/missing.crt`);
            assert(!run.ok);
            assertEquals(run.summary.error_code, "instance.host_unavailable");
            assertStringIncludes(run.summary.message, "no readable Orbit root CA");
            assertEquals(f.state().files ?? {}, {});
        } finally {
            f.cleanup();
        }
    },
});


const owned = { mode: "0700", uid: "2750", gid: "2750" };
const listed = (summary: { browser_trust: { container: string; reason: string }[] }) =>
    Object.fromEntries(summary.browser_trust.map((row) => [row.container.split("/")[1], row.reason]));

Deno.test({
    name: "only agent writes its NSS store, only in a running container: a new store holds only the entry, an existing one gets it appended once; a stopped or frozen one waits",
    ignore: !hasAnsible,
    fn() {
        // The second container's user had opened the store already; the third holds the entry from an earlier converge.
        const own = "library=\nname=NSS Internal PKCS #11 Module\nparameters=configdir='sql:/home/agent/.pki/nssdb'\nNSS=Flags=internal\n";
        const f = containers([
            { name: "app-default", status: "Running" }, { name: "app-open", status: "Running" }, { name: "app-done", status: "Running" },
            { name: "app-asleep", status: "Stopped" }, { name: "app-frozen", status: "Frozen" },
        ], {
            dirs: { ...homes(["app-default", "app-open", "app-done", "app-asleep", "app-frozen"]),
                "app-open/home/agent/.pki": owned, "app-open/home/agent/.pki/nssdb": owned,
                "app-done/home/agent/.pki": owned, "app-done/home/agent/.pki/nssdb": owned },
            files: {
                [`app-open${browserTrust.path}`]: { content: own, mode: "0600", uid: "2750", gid: "2750" },
                [`app-done${browserTrust.path}`]: { content: own + "\n" + browserTrust.entry, mode: "0600", uid: "2750", gid: "2750" },
            },
        });
        try {
            let run = pass(f.folder);
            assert(run.ok, JSON.stringify(run.summary));
            // A stopped or frozen container can wake while the step runs, so it gets no store now: the next converge that finds it
            // running writes it.
            assertEquals(listed(run.summary), { "app-asleep": "not running", "app-frozen": "not running" });
            const state = f.state();
            for (const name of ["app-asleep", "app-frozen"]) {
                assert(!Object.keys({ ...state.files, ...state.dirs }).some((path) => path.startsWith(`${name}/home/agent/.pki`)), name);
            }
            // A missing store: `agent` makes both folders and the file itself, closed to others by its umask.
            for (const directory of browserTrust.directories) assertEquals(state.dirs[`app-default${directory}`], owned);
            assertEquals(state.files[`app-default${browserTrust.path}`], { content: browserTrust.entry, mode: "0600", uid: "2750", gid: "2750" });
            // A store without the entry gets it after a blank line; its own text stays. A store with the entry stays as it was.
            assertEquals(state.files[`app-open${browserTrust.path}`].content, own + "\n" + browserTrust.entry);
            assertEquals(state.files[`app-done${browserTrust.path}`].content, own + "\n" + browserTrust.entry);
            const calls = f.calls();
            // Root never writes a store: only `agent`, by its own bounded exec, and never in a stopped or frozen container.
            const agent = calls.filter((call) => call[0] === "exec" && call.includes("--user"));
            assertEquals(agent.map((call) => call[call.indexOf("exec") + 3]).sort(), ["app-default", "app-done", "app-open"]);
            for (const call of agent) {
                assertEquals([call[call.indexOf("--user") + 1], call[call.indexOf("--group") + 1]], ["2750", "2750"]);
                assertEquals(call.slice(call.indexOf("--") + 1, call.indexOf("--") + 3), ["timeout", "30"]);
            }
            assert(!calls.some((call) => call[0] === "file" && call.some((arg) => /\/home\/agent/.test(arg))), JSON.stringify(calls));
            assert(!calls.some((call) => call[0] === "exec" && call.includes("app-frozen")));
            assertEquals(run.summary.written.filter((path: string) => path.includes("/home/agent/")).sort(), [
                "orbit-instances/app-default/home/agent/.pki/nssdb/pkcs11.txt",
                "orbit-instances/app-open/home/agent/.pki/nssdb/pkcs11.txt",
            ]);
            // A second pass writes nothing. Once the stopped container runs, the next converge writes its store.
            run = pass(f.folder);
            assertEquals(run.summary.written, []);
            const woken = f.state();
            woken.instances.find((row: { name: string }) => row.name === "app-asleep").status = "Running";
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(woken));
            run = pass(f.folder);
            assertEquals(listed(run.summary), { "app-frozen": "not running" });
            assertEquals(f.state().files[`app-asleep${browserTrust.path}`].content, browserTrust.entry);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a planted symlink, a file where the store folder goes and a folder named like the store are refused and listed; every other step still runs",
    ignore: !hasAnsible,
    fn() {
        const planted = {
            "run-link": { dirs: [".pki", ".pki/nssdb"], links: { ".pki/nssdb/pkcs11.txt": "/etc/passwd" } },
            "run-file": { dirs: [".pki"], files: { ".pki/nssdb": "planted" } },
            "run-folder": { dirs: [".pki", ".pki/nssdb", ".pki/nssdb/pkcs11.txt"] },
            "run-dirlink": { links: { ".pki": "/etc" } },
        } as Record<string, { dirs?: string[]; files?: Record<string, string>; links?: Record<string, string> }>;
        const dirs: Record<string, unknown> = homes([...Object.keys(planted), "app-default"]);
        const files: Record<string, unknown> = {};
        const links: Record<string, string> = {};
        for (const [name, row] of Object.entries(planted)) {
            for (const dir of row.dirs ?? []) dirs[`${name}/home/agent/${dir}`] = owned;
            for (const [path, content] of Object.entries(row.files ?? {})) files[`${name}/home/agent/${path}`] = { content, mode: "0644", uid: "2750", gid: "2750" };
            for (const [path, target] of Object.entries(row.links ?? {})) links[`${name}/home/agent/${path}`] = target;
        }
        const f = containers([...Object.keys(planted).map((name) => ({ name, status: "Running" })), { name: "app-default", status: "Running" }],
            { dirs, files, links });
        try {
            const run = pass(f.folder);
            assert(run.ok, JSON.stringify(run.summary));
            assertEquals(listed(run.summary), {
                "run-dirlink": "path refused: /home/agent/.pki is symlink",
                "run-file": "path refused: /home/agent/.pki/nssdb is file",
                "run-folder": "path refused: /home/agent/.pki/nssdb/pkcs11.txt is directory",
                "run-link": "path refused: /home/agent/.pki/nssdb/pkcs11.txt is symlink",
            });
            const state = f.state();
            // Nothing was written through or over a planted path.
            assertEquals(state.links["run-link/home/agent/.pki/nssdb/pkcs11.txt"], "/etc/passwd");
            assertEquals(state.links["run-dirlink/home/agent/.pki"], "/etc");
            assertEquals(state.files["run-file/home/agent/.pki/nssdb"].content, "planted");
            assertEquals(run.summary.failed, []);
            // Each refused container still gets its shared files, its root CA and its bundle; the healthy one its store.
            for (const name of Object.keys(planted)) assert(run.summary.written.includes(`orbit-instances/${name}/etc/gitconfig`), name);
            assertEquals(run.summary.ca_bundle, ["orbit-instances/app-default", ...Object.keys(planted).sort().map((name) => `orbit-instances/${name}`)]);
            assertEquals(state.files[`app-default${browserTrust.path}`].content, browserTrust.entry);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a container without /home/agent gets no NSS store and is listed by name; the step passes and keeps the library digest",
    ignore: !hasAnsible,
    fn() {
        const f = containers([{ name: "app-default", status: "Running" }, { name: "app-gone", status: "Running" }], { dirs: homes(["app-default"]) });
        try {
            const run = pass(f.folder);
            assert(run.ok, JSON.stringify(run.summary));
            assertEquals(listed(run.summary), { "app-gone": "no agent home" });
            assertEquals(run.summary.skipped, []);
            assert(!Object.keys(f.state().files).some((path) => path.startsWith("app-gone/home/")));
            // It still gets the shared files and the root CA, and the Node writes its digest: the next converge drops no cache again.
            assert(run.summary.written.includes("orbit-instances/app-gone/etc/gitconfig"));
            assert(f.state().files[`app-default${browserTrust.path}`]);
            assert(Deno.statSync(`${f.folder}/container-libraries`).isFile);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a container whose libp11-kit0 is not the trust module's version is listed with both versions, and agent takes an Orbit entry out of its store; the step passes",
    ignore: !hasAnsible,
    fn() {
        const own = "library=\nname=NSS Internal PKCS #11 Module\n";
        const f = containers([
            { name: "app-default", status: "Running" }, { name: "app-old", status: "Running" }, { name: "app-none", status: "Running" },
            { name: "app-later", status: "Running" },
        ], {
            dirs: { ...homes(["app-default", "app-old", "app-none", "app-later"]), "app-later/home/agent/.pki": owned, "app-later/home/agent/.pki/nssdb": owned },
            files: { [`app-later${browserTrust.path}`]: { content: own + "\n" + browserTrust.entry + "library=/other.so\nname=Other\n", mode: "0600", uid: "2750", gid: "2750" } },
            packages: { "app-old": { "libp11-kit0": "0.25.5-3" }, "app-none": { "libp11-kit0": null }, "app-later": { "libp11-kit0": "0.26.3-1" } },
        });
        try {
            const run = pass(f.folder);
            assert(run.ok, JSON.stringify(run.summary));
            assertEquals(listed(run.summary), {
                "app-later": "version mismatch: 0.26.3-1 != 0.26.2-2",
                "app-none": "version mismatch: not installed != 0.26.2-2",
                "app-old": "version mismatch: 0.25.5-3 != 0.26.2-2",
            });
            // Their browsers behave as before the store: no store is made, and an Orbit entry already there goes, with its name line
            // and its blank line; the user's own entries stay.
            for (const name of ["app-old", "app-none"]) assert(!Object.keys(f.state().dirs).some((path) => path.startsWith(`${name}/home/agent/.pki`)), name);
            assertEquals(f.state().files[`app-later${browserTrust.path}`].content, own + "\nlibrary=/other.so\nname=Other\n");
            for (const name of ["app-old", "app-none", "app-later"]) assert(run.summary.written.includes(`orbit-instances/${name}/etc/gitconfig`), name);
            assertEquals(f.state().files[`app-default${browserTrust.path}`].content, browserTrust.entry);
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "a container whose dpkg status or user output is not UTF-8, or whose store step hangs, is listed and fails nothing",
    ignore: !hasAnsible,
    fn() {
        const status = new TextEncoder().encode("Package: libp11-kit0\nStatus: install ok installed\nArchitecture: amd64\nVersion: 0.26.2-2\nDescription: ");
        const raw = btoa(String.fromCharCode(...status, 0xff, 0xfe, 0x0a));
        const f = containers([
            { name: "app-default", status: "Running" }, { name: "app-bytes", status: "Running" }, { name: "app-noisy", status: "Running" },
            { name: "app-hung", status: "Running" },
        ], { dirs: homes(["app-default", "app-bytes", "app-noisy", "app-hung"]), raw_status: { "app-bytes": raw },
            agent_bytes: ["app-noisy"], agent_hangs: ["app-hung"] });
        try {
            const run = pass(f.folder);
            assert(run.ok, JSON.stringify(run.summary));
            // A status that is not UTF-8 still reads: its libp11-kit0 matches, so that container gets its store.
            assertEquals(listed(run.summary), { "app-hung": "store failed: timeout", "app-noisy": "store failed: exit 0" });
            assertEquals(f.state().files[`app-bytes${browserTrust.path}`].content, browserTrust.entry);
            for (const name of ["app-noisy", "app-hung"]) assert(run.summary.written.includes(`orbit-instances/${name}/etc/gitconfig`), name);
            assertEquals(run.summary.failed, []);
        } finally {
            f.cleanup();
        }
    },
});

/** The folder build's own script on a staging folder of the given Debian package parts, unpacked as the build unpacks them. */
async function stage(root: string, version: string, parts: { name: string; version?: string; trust_module?: Record<string, string> }[], debs: Record<string, string>) {
    const dir = `${root}/${version}`;
    for (const part of parts) {
        Deno.mkdirSync(`${dir}/${part.name}`, { recursive: true });
        const unpacked = new Deno.Command("dpkg-deb", { args: ["-x", debs[part.name], `${dir}/${part.name}`] }).outputSync();
        assert(unpacked.success, new TextDecoder().decode(unpacked.stderr));
    }
    const out = new Deno.Command("python3", { args: ["-I", new URL("../ansible/instance_folder_debs.py", import.meta.url).pathname, dir, JSON.stringify(parts)] }).outputSync();
    assert(out.success, new TextDecoder().decode(out.stdout));
    return dir;
}

Deno.test({
    name: "the trust module's package only adds its module to the runtime folder: no restart, and nothing new in lib",
    async fn() {
        const f = { folder: Deno.makeTempDirSync() };
        try {
            Deno.mkdirSync(`${f.folder}/artifacts`);
            const nss = await deb(f.folder, "libfake3", { "usr/lib/x86_64-linux-gnu/libfake3.so": "library" });
            const trust = await deb(f.folder, "p11-kit-modules", {
                "usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-trust.so": "trust module",
                "usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-client.so": "client module",
                "usr/lib/x86_64-linux-gnu/libp11-kit-fake.so.0": "a library the folder must not link",
            });
            const debs = { [nss.name]: `${f.folder}/artifacts/libfake3_1.0_amd64.deb`, [trust.name]: `${f.folder}/artifacts/p11-kit-modules_1.0_amd64.deb` };
            const module = { source: "usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-trust.so", path: "pkcs11/p11-kit-trust.so", library: "libp11-kit0" };
            const before = await stage(f.folder, "old", [{ name: nss.name }], debs);
            const after = await stage(f.folder, "new", [{ name: nss.name }, { name: trust.name, version: "1.0", trust_module: module }], debs);
            assert(same(before, after), "the module's part and its link restart nothing");
            assertEquals([...Deno.readDirSync(`${after}/lib`)].map((e) => e.name).sort(), [...Deno.readDirSync(`${before}/lib`)].map((e) => e.name).sort());
            assertEquals([...Deno.readDirSync(`${after}/pkcs11`)].map((e) => e.name).sort(), ["p11-kit-trust.so", "requires"]);
            // What the module needs, for an agent in a container to compare with its own library.
            assertEquals(Deno.readTextFileSync(`${after}/pkcs11/requires`), "libp11-kit0 1.0\n");
            assertEquals(Deno.readTextFileSync(`${after}/pkcs11/p11-kit-trust.so`), "trust module");
            // A package that lacks its module fails the build by name.
            const broken = new Deno.Command("python3", { args: ["-I", new URL("../ansible/instance_folder_debs.py", import.meta.url).pathname, before,
                JSON.stringify([{ name: nss.name, trust_module: module }])] }).outputSync();
            assert(!broken.success);
            assertStringIncludes(new TextDecoder().decode(broken.stdout), "browser-libfake3/usr/lib/x86_64-linux-gnu/pkcs11/p11-kit-trust.so");
        } finally {
            Deno.removeSync(f.folder, { recursive: true });
        }
    },
});
