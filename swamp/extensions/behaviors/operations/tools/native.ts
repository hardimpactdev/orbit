import { z } from "npm:zod@4.4.3";
import { type ToolInput, ToolRefusal } from "./contract.ts";

type Input =
    & Pick<
        ToolInput,
        | "manager"
        | "package"
        | "platform"
        | "architecture"
        | "repository"
        | "repository_packages"
    >
    & { user: string; kind?: string; desired_version?: string | null; package_owned?: boolean };
type Step = { status: string; outputs?: Record<string, unknown> } | null;
/** The origin apt matches `Pin: origin` against: the URI's host as written, an IPv6 literal without its brackets and unnormalized. */
export function origin(uri: string): string {
    const host = /^[a-z][a-z0-9+.-]*:\/\/(\[[^\]]*\]|[^/:?#]*)/i.exec(uri)?.[1] ?? "";
    return host.replace(/^\[(.*)\]$/, "$1").toLowerCase();
}
/** Apt preferences of one Orbit repository: priority 990 for the listed packages, and 1 for every other package from its origins. */
export function preferences(
    repo: ToolInput["repository"],
    packages: string[],
): string {
    if (repo === null) return "";
    const hosts = [...new Set(repo.uris.map(origin))];
    return hosts.map((host) => {
        const preferred = packages.length === 0
            ? ""
            : `Package: ${packages.join(" ")}\nPin: origin "${host}"\nPin-Priority: 990\n\n`;
        return `${preferred}Package: *\nPin: origin "${host}"\nPin-Priority: 1\n`;
    }).join("\n");
}
const object = (v: unknown) => z.record(z.string(), z.unknown()).parse(v);
export function stdout(step: Step, absent = false): string {
    if (
        step?.status !== "succeeded" ||
        (!absent && step.outputs?.exitCode !== 0)
    ) throw new ToolRefusal("tool.version_probe_failed");
    return z.string().parse(step.outputs?.stdout);
}
/** The Linux cask API refresh succeeded: exit 0 and no warning that Homebrew fell back to its cached API data. */
export function refreshed(step: Step): void {
    const output = step?.status === "succeeded" && step.outputs?.exitCode === 0
        ? String(step.outputs.stdout ?? "") + String(step.outputs.stderr ?? "")
        : null;
    if (output === null || /falling back to cached/i.test(output)) {
        throw new ToolRefusal("tool.candidate_version_probe_failed");
    }
}
/**
 * The Linux Homebrew prefix rule after a brew write (docs/reference/tools.md#readable-homebrew-prefix): each entry others cannot read becomes readable
 * for group and others, and a directory also searchable, so the Node agent's observer reads it without privilege. It touches only entries
 * that lack those bits, prints each one it changes, and keeps owner and write bits.
 */
export function homebrewReadable(prefix = "/home/linuxbrew/.linuxbrew"): string[] {
    return [
        "/usr/bin/find", prefix,
        "(", "-type", "d", "!", "-perm", "-055", "-print", "-exec", "/usr/bin/chmod", "go+rx", "{}", "+", ")", "-o",
        "(", "!", "-type", "d", "!", "-type", "l", "!", "-perm", "-044", "-print", "-exec", "/usr/bin/chmod", "go+r", "{}", "+", ")",
    ];
}
/** Public argv only. Scope ownership is admission's concern, not a Node phase. */
export function commands(input: Input) {
    const brew = input.manager.startsWith("brew"),
        cask = input.manager === "brew-cask";
    const prefix = input.platform === "linux"
        ? "/home/linuxbrew/.linuxbrew"
        : ["arm64", "aarch64"].includes(input.architecture)
        ? "/opt/homebrew"
        : "/usr/local";
    const home = (input.platform === "linux" ? "/home/" : "/Users/") +
        input.user;
    const scope = input.platform === "linux"
        ? "/opt/orbit/vite-plus"
        : home + "/.vite-plus";
    const binary = input.manager === "apt"
        ? "/usr/bin/apt-get"
        : brew
        ? prefix + "/bin/brew"
        : input.manager === "composer"
        ? "/usr/bin/composer"
        : input.platform === "linux"
        ? "/usr/local/bin/vp"
        : scope + "/bin/vp";
    const env = [
        "/usr/bin/env",
        "LC_ALL=C",
        ...(brew
            ? [
                "HOMEBREW_NO_AUTO_UPDATE=1",
                "HOMEBREW_NO_ANALYTICS=1",
                "HOMEBREW_NO_INSTALL_CLEANUP=1",
                "HOMEBREW_NO_AUTOREMOVE=1",
                "HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1",
                // Casks refresh the API metadata on Linux too, so Orbit sees a new cask build without `brew update`.
                ...(input.platform === "macos" || cask
                    ? ["HOMEBREW_FORCE_API_AUTO_UPDATE=1"]
                    : []),
            ]
            : input.manager === "composer"
            ? ["COMPOSER_HOME=/opt/orbit/composer"]
            : input.manager === "vp" && input.platform !== "linux"
            ? ["VP_HOME=" + scope]
            : []),
    ];
    const run = (...args: string[]) => [...env, binary, ...args];
    const formula = cask ? "--cask" : "--formula",
        qualified = (cask ? "homebrew/cask/" : "homebrew/core/") +
            input.package;
    const install = input.kind === "tool.install";
    const exact = input.desired_version ?? null;
    const composerRequire = install || exact !== null;
    const composer = [
        "global",
        composerRequire ? "require" : "update",
        input.package + (exact !== null ? ":" + exact : install ? ":*" : ""),
        "--no-interaction",
        "--no-ansi",
        "--no-progress",
        "--no-audit",
        "--with-all-dependencies",
    ];
    const repo = input.repository;
    const aptOptions = repo
        ? [
            "-o",
            "Dir::Etc::sourcelist=/etc/apt/sources.list.d/orbit-" + repo.name +
            ".sources",
            "-o",
            "Dir::Etc::sourceparts=-",
            "-o",
            "Dir::State::status=/dev/null",
            "-o",
            "Dir::Cache::pkgcache=",
            "-o",
            "Dir::Cache::srcpkgcache=",
        ]
        : [];
    return {
        prefix,
        home,
        scope,
        repository_packages: input.repository_packages,
        // Once the run verified its package: the owned packages of the repository and this one.
        repository_preferences: preferences(repo, input.repository_packages),
        // Before the package write and whenever the run stops short of acquiring: only what Orbit already owns, so no interruption can
        // leave an unacquired package preferred. Selection reads only the repository's source list and installs an exact version.
        repository_preferences_held: preferences(
            repo,
            input.package_owned !== false
                ? input.repository_packages
                : input.repository_packages.filter((name) => name !== input.package),
        ),
        repository: input.repository ??
            {
                name: "unused",
                uris: [],
                suites: [],
                components: [],
                signing_key: {
                    url: "https://unused.invalid/key",
                    fingerprint: "",
                },
            },
        version: run("--version"),
        readable: brew && input.platform === "linux" ? homebrewReadable(prefix) : null,
        os: ["/usr/bin/sw_vers", "-productVersion"],
        // Linux `brew info` reads the cached cask API; `outdated` is an auto-update command, so it refreshes that
        // API first (HOMEBREW_FORCE_API_AUTO_UPDATE) without `brew update`.
        refresh: cask ? run("outdated", "--cask", "--quiet") : [
            ...env,
            "/usr/bin/apt-get",
            ...aptOptions,
            "-o",
            "APT::Get::List-Cleanup=false",
            "update",
            "--error-on=any",
        ],
        inventory: input.manager === "apt"
            ? [
                ...env,
                "/usr/bin/dpkg-query",
                "--show",
                "--showformat=${Package}\t${Status}\t${Version}\n",
                "--",
                input.package,
            ]
            : brew
            ? run("info", "--json=v2", formula, "--installed")
            : input.manager === "composer"
            ? run("global", "show", "--direct", "--format=json", "--no-ansi")
            : run("list", "-g", "--json"),
        candidate: input.manager === "apt"
            ? [
                ...env,
                "/usr/bin/apt-cache",
                ...aptOptions,
                exact === null ? "policy" : "madison",
                "--",
                input.package,
            ]
            : brew
            ? run("info", "--json=v2", formula, qualified)
            : input.manager === "composer"
            ? run(...composer, "--dry-run")
            : run("info", input.package + (exact === null ? "" : "@" + exact.replace(/^v/, "")), "version", "--json"),
        plan: input.manager === "apt"
            ? run("--simulate", "remove", "--", input.package)
            : input.manager === "brew"
            ? run("uses", "--installed", input.package)
            : run("--version"),
        write: brew
            ? run(
                install ? "install" : "upgrade",
                formula,
                ...(!cask ? ["--force-bottle"] : []),
                qualified,
            )
            : input.manager === "composer"
            ? run(...composer)
            : run(
                install || exact !== null ? "install" : "update",
                "-g",
                input.package + (exact === null ? "" : "@" + exact.replace(/^v/, "")),
                ...(install
                    ? ["--node", "lts"]
                    : exact === null ? ["--reinstall-node-mismatch"] : []),
            ),
        remove: brew
            ? run("uninstall", formula, input.package)
            : input.manager === "composer"
            ? run(
                "global",
                "remove",
                input.package,
                "--no-interaction",
                "--no-ansi",
                "--no-progress",
                "--no-audit",
            )
            : run("remove", "-g", input.package),
    };
}

export function aptWrite(packageName: string, version: string, exact: boolean): string[] {
    return [
        "/usr/bin/env", "LC_ALL=C", "DEBIAN_FRONTEND=noninteractive",
        "/usr/bin/apt-get", "-o", "DPkg::Lock::Timeout=300", "install",
        "--yes", "--no-install-recommends", "--no-remove",
        ...(exact ? ["--allow-downgrades"] : []), "--", `${packageName}=${version}`,
    ];
}
export function inventory(
    input: Input,
    version: Step,
    query: Step,
    os: Step = null,
) {
    const c = commands(input),
        manager_version = stdout(version).trim().split("\n")[0];
    const text = stdout(query, input.manager === "apt");
    let versions: unknown[] = [], extra: Record<string, unknown> = {};
    if (input.manager === "apt") {
        if (
            query?.outputs?.exitCode === 1 && !text &&
            String(query.outputs.stderr).trim() ===
                `dpkg-query: no packages found matching ${input.package}`
        ) versions = [];
        else {
            const [name, status, value] = text.trim().split("\t");
            if (query?.outputs?.exitCode !== 0 || name !== input.package) {
                throw new ToolRefusal("tool.version_probe_failed");
            }
            // A package held with `apt-mark hold` is installed; apt will not change it.
            if (status === "install ok installed") versions = [value];
            else if (status === "hold ok installed") {
                versions = [value];
                extra = { held: true };
            } else if (
                !/^(deinstall|purge|unknown) ok (config-files|not-installed)$/
                    .test(status)
            ) throw new ToolRefusal("tool.version_probe_failed");
        }
    } else {
        const raw = JSON.parse(text);
        if (input.manager.startsWith("brew")) {
            const rows = z.array(z.record(z.string(), z.unknown())).parse(
                raw[input.manager === "brew" ? "formulae" : "casks"],
            );
            const selected = rows.filter((r) =>
                r[input.manager === "brew" ? "name" : "token"] === input.package
            );
            if (selected.length > 1) {
                throw new ToolRefusal("tool.version_probe_failed");
            }
            const record = selected[0];
            versions = !record
                ? []
                : input.manager === "brew"
                ? z.array(z.object({ version: z.string() })).parse(
                    record.installed,
                ).map((r) => r.version)
                : record.installed
                ? [record.installed]
                : [];
            extra = {
                homebrew: record ?? null,
                prefix: c.prefix,
                home: c.home,
                token_count: selected.length,
                os_major: input.platform === "macos" && os !== null
                    ? Number(stdout(os).trim().split(".")[0])
                    : 0,
            };
        } else {
            const rows = z.array(
                z.object({ name: z.string(), version: z.string().optional() }),
            ).parse(input.manager === "vp" ? raw : raw.installed ?? []);
            versions = rows.filter((r) => r.name === input.package).map((r) =>
                r.version
            );
            extra = input.manager === "composer"
                ? { roots: rows.map((r) => r.name) }
                : { scope_path: c.scope };
        }
    }
    return { manager_version, versions, complete: true, ...extra };
}
export function candidate(
    input: Input,
    step: Step,
    facts: Record<string, unknown>,
) {
    const text = stdout(step);
    if (input.manager === "apt") {
        const exact = input.desired_version ?? null;
        if (exact !== null) {
            const available = text.split("\n").some((line) => {
                const [name, version, archive] = line.split("|").map((part) => part.trim());
                return name === input.package && version === exact && Boolean(archive);
            });
            return { version: available ? exact : null };
        }
        const matches = text.split("\n").map((s) =>
            /^\s*Candidate:\s+(\S+)\s*$/.exec(s)
        ).filter((v) => v !== null);
        if (matches.length !== 1) {
            throw new ToolRefusal("tool.candidate_version_probe_failed");
        }
        return { version: matches[0]![1] === "(none)" ? null : matches[0]![1] };
    }
    if (input.manager === "vp") return { version: JSON.parse(text) };
    if (input.manager.startsWith("brew")) {
        const data = object(JSON.parse(text));
        const rows = z.array(z.record(z.string(), z.unknown())).parse(
            data[input.manager === "brew" ? "formulae" : "casks"],
        );
        if (rows.length !== 1) {
            throw new ToolRefusal("tool.candidate_version_probe_failed");
        }
        return { ...facts, homebrew: rows[0] };
    }
    const lines = (text + "\n" + String(step?.outputs?.stderr ?? "")).split(
        "\n",
    );
    const selected: string[] = [];
    for (const line of lines) {
        const match =
            /^\s*-\s+(?:Locking|Installing)\s+(\S+)\s+\(([^\s()]+)\)\s*$/.exec(
                line,
            ) ??
                /^\s*-\s+(?:Upgrading|Downgrading)\s+(\S+)\s+\([^\s()]+\s+=>\s+([^\s()]+)\)\s*$/
                    .exec(line);
        if (match?.[1] === input.package) selected.push(match[2]);
    }
    if (
        !selected.length &&
        lines.includes("Nothing to install, update or remove")
    ) return { version: (facts.versions as string[])[0] };
    if (!selected.length || new Set(selected).size !== 1) {
        throw new ToolRefusal("tool.candidate_version_probe_failed");
    }
    return { version: selected[0] };
}
export function removalPlan(input: Input, step: Step) {
    const text = stdout(step);
    return {
        packages: input.manager === "apt"
            ? [
                ...new Set(
                    text.split("\n").filter((s) => /^\s*Remv\b/.test(s)).map((
                        s,
                    ) => s.trim().split(/\s+/)[1].split(":")[0]),
                ),
            ]
            : [input.package, ...text.trim().split(/\s+/).filter(Boolean)],
    };
}

/** Package state from an official behavior check, or the manager's one read command. */
export function after(input: Input, value: Record<string, unknown> | null) {
    if (value === null) throw new ToolRefusal("tool.version_probe_failed");
    if (input.manager === "apt") {
        const state = z.object({
            status: z.enum(["compliant", "non_compliant"]),
            packages: z.array(
                z.object({
                    name: z.string(),
                    installed: z.boolean(),
                    version: z.string().nullable(),
                }),
            ),
        }).parse(value);
        const selected = state.packages.filter((p) => p.name === input.package);
        if (selected.length !== 1) {
            throw new ToolRefusal("tool.version_probe_failed");
        }
        return {
            manager_version: input.manager,
            versions: selected[0].installed ? [selected[0].version] : [],
            complete: true,
        };
    }
    return inventory(input, {
        status: "succeeded",
        outputs: { stdout: input.manager, exitCode: 0 },
    }, { status: "succeeded", outputs: value });
}
