import { z } from "npm:zod@4.4.3";
import { Setup } from "./tool_setup_input.ts";

type Row = Record<string, unknown>;
const Home = z.string().regex(/^\/[^\r\n\0]+$/).refine((value) =>
    value !== "/"
);
/** Public resource descriptions only. Native workflow steps own downloads and every Node effect. */
export async function toolSetupPlan(raw: unknown, home: string) {
    const setup = Setup.parse(raw);
    Home.parse(home);
    const user = setup.input.user;
    const cache = "/var/cache/orbit/tool-setup/" + setup.manager;
    const directories: Row[] = [{ path: cache, owner: "root", mode: "0755" }];
    const files: Row[] = [],
        fetches: Row[] = [],
        sha512: Row[] = [],
        archives: Row[] = [],
        copies: Row[] = [],
        links: Row[] = [],
        commands: Row[] = [];
    const dir = (path: string, owner = user) =>
        directories.push({ path, owner, mode: "0755" });
    const file = (path: string, content: string, owner = user, mode = "0644") =>
        files.push({ path, content, owner, mode });
    const link = (path: string, target: string) =>
        links.push({ path, target, owner: user });
    const fetch = (
        name: string,
        pin: { urls: string[]; algorithm: string; digest: string },
        packageIndex: number | null = null,
    ) => {
        const path = cache + "/" + name;
        fetches.push({
            path,
            package_index: packageIndex,
            urls: pin.urls,
            owner: "root",
            mode: "0644",
            checksum: pin.algorithm === "sha256"
                ? "sha256:" + pin.digest
                : null,
        });
        if (pin.algorithm === "sha512") {
            sha512.push({
                path,
                package_index: packageIndex,
                stdin: pin.digest + "  " + path + "\n",
            });
        }
        return path;
    };
    const apt = setup.apt;
    let aptArgs: string[] = [],
        aptOptions: string[] = [],
        aptUpdateOptions: string[] = [];
    if (apt !== null) {
        const sources = (scheme: string) =>
            ["resolute", "resolute-updates", "resolute-security"].map((suite) =>
                "deb [check-valid-until=no signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg] " +
                scheme + "://snapshot.ubuntu.com/ubuntu/" +
                apt.snapshot + "/ " + suite +
                " main restricted universe multiverse\n"
            ).join("");
        const source = cache + "/sources.list";
        file(source, sources("https"), "root");
        dir(cache + "/lists/partial", "root");
        const lists = (list: string) => [
            "-o",
            "Dir::Etc::sourcelist=" + list,
            "-o",
            "Dir::Etc::sourceparts=-",
            "-o",
            "Dir::State::Lists=" + cache + "/lists",
            "-o",
            "APT::Get::List-Cleanup=false",
        ];
        aptOptions = lists(source);
        aptUpdateOptions = aptOptions;
        // A package cache serves the indexes only. The Node speaks plain http to it and it fetches over https; apt still checks the
        // signatures. The packages stay a direct download: through the cache most of them failed. The Node asks for the index files
        // the image build asked for (it ignores the drop-ins of apt.conf.d, which add the appstream and command-not-found files),
        // and one request at a time: the cache answers some of several requests in flight with the body of another file.
        const proxy = apt.proxy ?? null;
        if (proxy !== null) {
            const cached = cache + "/sources-cache.list";
            file(cached, sources("http"), "root");
            aptUpdateOptions = [
                ...lists(cached),
                "-o",
                "Acquire::http::Proxy=" + proxy,
                "-o",
                "Dir::Etc::parts=-",
                "-o",
                "Acquire::http::Pipeline-Depth=0",
            ];
        }
        aptArgs = apt.packages.map((pkg, index) =>
            pkg.artifact === null
                ? pkg.name + "=" + pkg.version
                : fetch(pkg.name + ".deb", pkg.artifact, index)
        );
    }
    if (setup.manager === "composer") {
        for (
            const path of [
                "/opt/orbit/composer",
                "/opt/orbit/composer/vendor",
                "/opt/orbit/composer/vendor/bin",
            ]
        ) dir(path);
    } else if (setup.manager === "homebrew") {
        const prefix = "/home/linuxbrew/.linuxbrew";
        dir("/home/linuxbrew", "root");
        dir(prefix);
        for (
            const path of [
                "bin",
                "Cellar",
                "Caskroom",
                "Frameworks",
                "etc",
                "include",
                "lib",
                "opt",
                "sbin",
                "share",
                "var",
                "var/homebrew",
                "var/homebrew/locks",
            ]
        ) dir(prefix + "/" + path);
        link(prefix + "/bin/brew", "../Homebrew/bin/brew");
    } else {
        const tools = setup.input.tools;
        const scope = home + "/.local/share/vite-plus";
        for (
            const path of [
                home + "/.local",
                home + "/.local/share",
                scope,
                scope + "/bin",
                scope + "/recipe",
                scope + "/js_runtime",
                scope + "/js_runtime/node",
                scope + "/node_modules",
            ]
        ) dir(path);
        const node = scope + "/js_runtime/node/" + tools.node.version;
        const pnpm = scope + "/recipe/pnpm-" + tools.pnpm.version;
        for (
            const [name, pin] of [["node", tools.node], [
                "vp-cli",
                tools["vp-cli"],
            ], ["pnpm", tools.pnpm]] as const
        ) {
            const format = new URL(pin.urls[0]).pathname.endsWith(".xz")
                ? "tar.xz"
                : "tar.gz";
            const source = fetch(name + "." + format, pin),
                dest = cache + "/" + name + "-extracted";
            const marker = name === "node"
                ? "/node-v" + pin.version + "-linux-x64/bin/node"
                : name === "pnpm"
                ? "/package/pnpm"
                : "/vp";
            dir(dest, "root");
            archives.push({
                source,
                dest,
                format,
                creates: dest + marker,
            });
            if (name === "node") {
                dir(node);
                copies.push({
                    argv: [
                        "timeout",
                        "60",
                        "cp",
                        "-a",
                        dest + "/node-v" + pin.version + "-linux-x64/.",
                        node + "/",
                    ],
                });
            } else if (name === "pnpm") {
                dir(pnpm);
                copies.push({
                    argv: [
                        "timeout",
                        "60",
                        "cp",
                        "-a",
                        dest + "/package/.",
                        pnpm + "/",
                    ],
                });
            } else {copies.push({
                    argv: [
                        "timeout",
                        "60",
                        "install",
                        "-m",
                        "0755",
                        "-o",
                        user,
                        dest + "/vp",
                        scope + "/bin/vp",
                    ],
                });}
        }
        copies.push({
            argv: [
                "timeout",
                "60",
                "install",
                "-m",
                "0755",
                "-o",
                user,
                pnpm + "/pnpm",
                scope + "/bin/pnpm",
            ],
        });
        copies.push({
            argv: ["timeout", "60", "chown", "-R", user, node, pnpm],
        });
        const digest = Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(setup.input.vp_lock),
                ),
            ),
            (n) => n.toString(16).padStart(2, "0"),
        ).join("");
        if (digest !== tools.vp.lock_sha256) {
            throw new Error("node.tool_lock_mismatch");
        }
        const lock = z.object({ name: z.string(), version: z.string() })
            .passthrough().parse(JSON.parse(setup.input.vp_lock));
        const runtime = scope + "/recipe/vp-runtime-" + digest;
        dir(runtime);
        file(
            runtime + "/package.json",
            JSON.stringify({
                name: lock.name,
                version: lock.version,
                private: true,
                dependencies: { "vite-plus": tools.vp.version },
            }),
        );
        file(runtime + "/package-lock.json", setup.input.vp_lock);
        file(scope + "/bin/.vp-setup-complete", "");
        file(
            scope + "/.orbit-recipe-node.json",
            JSON.stringify({ version: tools.node.version }),
        );
        commands.push({
            argv: [
                "timeout",
                "900",
                "sudo",
                "-n",
                "-u",
                user,
                "-H",
                "--",
                "env",
                "-i",
                "HOME=" + home,
                "VP_HOME=" + scope,
                "PATH=" + node + "/bin:/usr/bin:/bin",
                node + "/bin/node",
                node + "/lib/node_modules/npm/bin/npm-cli.js",
                "ci",
                "--ignore-scripts",
                "--no-audit",
                "--no-fund",
                "--prefix",
                runtime,
            ],
        });
        link(
            scope + "/node_modules/vite-plus",
            runtime + "/node_modules/vite-plus",
        );
        link(scope + "/recipe/vp-current", runtime + "/node_modules/vite-plus");
        link(scope + "/recipe/pnpm-current", pnpm);
        for (const name of ["node", "npm", "npx"]) {
            link(scope + "/bin/" + name, "vp");
        }
        for (const name of ["vp", "node", "pnpm", "npm", "npx"]) {
            file(
                "/usr/local/bin/" + name,
                '#!/bin/sh\nexport VP_HOME="' + scope + '"\nexec "' + scope +
                    "/bin/" + name + '" "$@"\n',
                "root",
                "0755",
            );
        }
        commands.push({
            argv: [
                "timeout",
                "60",
                "sudo",
                "-n",
                "-u",
                user,
                "-H",
                "--",
                "env",
                "VP_HOME=" + scope,
                scope + "/bin/vp",
                "env",
                "default",
                tools.node.version,
            ],
        });
    }
    const aptInstallPrefix: string[] = apt === null ? [] : [
        "timeout",
        "900",
        "env",
        "DEBIAN_FRONTEND=noninteractive",
        "apt-get",
        "-o",
        "DPkg::Lock::Timeout=300",
        ...aptOptions,
        "install",
        "--yes",
        "--no-install-recommends",
        "--no-remove",
        "--",
    ];
    return {
        setup,
        home,
        directories,
        files: files.filter((row) =>
            !String(row.path).startsWith("/usr/local/bin/")
        ),
        launchers: files.filter((row) =>
            String(row.path).startsWith("/usr/local/bin/")
        ),
        sha256_fetches: fetches.filter((x) => x.checksum !== null),
        sha512_fetches: fetches.filter((x) => x.checksum === null),
        sha512,
        archives,
        copies,
        links,
        commands,
        apt_update: apt === null ? [] : [
            "timeout",
            "900",
            "apt-get",
            "-o",
            "DPkg::Lock::Timeout=300",
            ...aptUpdateOptions,
            "update",
        ],
        apt_install: apt === null ? [] : [...aptInstallPrefix, ...aptArgs],
        apt_install_prefix: aptInstallPrefix,
        apt_arguments: (apt?.packages ?? []).map((pkg, index) => ({
            index,
            argv: pkg.artifact === null
                ? pkg.name + "=" + pkg.version
                : cache + "/" + pkg.name + ".deb",
        })),
        apt_packages: apt?.packages ?? [],
        apt_artifacts: (apt?.packages ?? []).map((pkg, index) => ({
            package_index: index,
            artifact: pkg.artifact,
            name: pkg.name,
            version: pkg.version,
            path: cache + "/" + pkg.name + ".deb",
        })).filter((pkg) => pkg.artifact !== null),
    };
}
