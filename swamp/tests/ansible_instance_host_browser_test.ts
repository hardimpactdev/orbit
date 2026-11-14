/**
 * The runtime folder's browser libraries and fonts, and the image's shared files in every existing container
 * (docs/reference/development-containers.md#browser-libraries-and-fonts, #existing-containers).
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

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

            const after = f.state();
            // Every running and stopped container gets each shared file; a frozen one is left alone.
            // Running, stopped and frozen containers all get each shared file through the file API.
            for (const name of ["app-default", "app-asleep", "app-frozen"]) {
                for (const file of shared) {
                    assertEquals(after.files[`${name}${file.path}`], { content: file.content, mode: "0644", uid: "0", gid: "0" });
                }
            }
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

/** The container step's own script against the fixture's Incus, without a whole converge; `root` is the Node's CA file. */
function pass(folder: string, root = `${folder}/orbit-root-ca.crt`) {
    const out = new Deno.Command("python3", {
        args: ["-I", new URL("../ansible/instance_container_files.py", import.meta.url).pathname, JSON.stringify({
            projects: ["orbit-instances", "orbit-instances-nested"], files: shared.map(({ path, content, mode }) => ({ path, content, mode })),
            trust: { source: root, path: "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt", directory: "/etc/ssl/certs" },
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

