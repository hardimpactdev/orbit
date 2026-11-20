import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { normalizeVersion, publicVersion, ToolRefusal } from "./contract.ts";
import { homebrewBlock } from "./homebrew.ts";
import { commands, inventory } from "./native.ts";
import { removalDecision, RemoveInput } from "./remove.ts";
import { behavior, decide, WriteInput } from "./write.ts";

// The `antigravity-cli` record that `brew info --json=v2 --cask` printed on Beast (Linux x86_64, Homebrew 7.0.13),
// installed fields trimmed. Linux Homebrew reads an API file already resolved for x86_64_linux: no `variations` and
// no `supported_platforms`.
const real =
    '{"token":"antigravity-cli","full_token":"antigravity-cli","old_tokens":[],"tap":"homebrew/cask","name":["Google Antigravity CLI"],"desc":"Terminal interface for Antigravity agents","homepage":"https://antigravity.google/product/antigravity-cli","url":"https://storage.googleapis.com/antigravity-public/antigravity-cli/1.5.13-5999302598852608/linux-x64/cli_linux_x64.tar.gz","url_specs":{},"version":"1.5.13,5999302598852608","autobump":true,"no_autobump_message":null,"skip_livecheck":false,"bundle_version":null,"bundle_short_version":null,"pinned":false,"pinned_version":null,"outdated":true,"sha256":"7fa9cc6df0d09562693d637c406a86c1b0f52ab3364714b1fd2d79cc6060d4b2","artifacts":[{"command_wrapper":["agy",{"executable":"/home/linuxbrew/.linuxbrew/Caskroom/antigravity-cli/1.5.13,5999302598852608/antigravity","env":{"AGY_CLI_DISABLE_AUTO_UPDATE":"true"}}],"target":"/home/linuxbrew/.linuxbrew/bin/agy"},{"zap":[{"trash":"~/.gemini/antigravity-cli"}]}],"caveats":"`agy`\'s automatic updates are disabled so that Homebrew manages them.\\nUse `brew upgrade --cask antigravity-cli` instead of `agy update`.\\n","caveats_rosetta":null,"depends_on":{},"conflicts_with":null,"container":null,"rename":[],"auto_updates":null,"deprecated":false,"deprecation_date":null,"deprecation_reason":null,"deprecation_replacement_formula":null,"deprecation_replacement_cask":null,"deprecate_args":null,"disabled":false,"disable_date":null,"disable_reason":null,"disable_replacement_formula":null,"disable_replacement_cask":null,"disable_args":null,"languages":[],"ruby_source_path":"Casks/a/antigravity-cli.rb"}';
const prefix = "/home/linuxbrew/.linuxbrew";
const version = "1.5.13,5999302598852608";
const cask = JSON.parse(real);
const base = {
    node_id: 9,
    manager: "brew-cask",
    package: "antigravity-cli",
    user: "orbit",
    platform: "linux",
    architecture: "x86_64",
};
const brew = { os_major: 0, prefix, home: "/home/orbit", token_count: 1 };
const facts = (versions: string[]) => ({
    scope: "present",
    manager_version: "Homebrew 5.0.0",
    versions,
    complete: true,
    ...brew,
    homebrew: cask,
});
const write = (changes: Record<string, unknown> = {}) =>
    WriteInput.parse({ kind: "tool.install", ...base, ...changes });
const candidate = (changes: Record<string, unknown> = {}) => ({
    ...brew,
    homebrew: { ...cask, ...changes },
});

Deno.test("Linux casks install the command_wrapper cask under a comma-build constraint", () => {
    const install = decide(write({ version_constraint: "^1.5" }), {
        facts: facts([]),
        candidate: candidate(),
    });
    assertEquals([install.ready, install.mutate, install.outcome], [
        true,
        true,
        "applied",
    ]);
    assertEquals(
        decide(write({ version_constraint: "^2.0" }), {
            facts: facts([]),
            candidate: candidate(),
        }).error_code,
        "tool.version_constraint_blocked",
    );
    // A wrapper that writes outside the Homebrew prefix or the account's home needs authorization.
    assertEquals(
        homebrewBlock(write(), {
            ...brew,
            homebrew: {
                ...cask,
                artifacts: [{ ...cask.artifacts[0], target: "/usr/bin/agy" }],
            },
        }, true),
        "authorization_required",
    );
    assertEquals(
        homebrewBlock(write(), candidate({ sha256: "no_check" }), true),
        "checksum_unavailable",
    );
});

Deno.test("the real Linux antigravity-cli record passes the full install and update policy", () => {
    assertEquals(
        homebrewBlock(write(), { ...brew, homebrew: cask }, true),
        null,
    );
    const install = decide(write({ version_constraint: "^1.5" }), {
        facts: facts([]),
        candidate: { ...brew, homebrew: JSON.parse(real) },
    });
    assertEquals([install.ready, install.mutate, install.outcome], [
        true,
        true,
        "applied",
    ]);
    const update = decide(
        write({ kind: "tool.update", tool_id: 1, version_constraint: "^1.5" }),
        {
            facts: facts(["1.5.12,5000000000000000"]),
            candidate: { ...brew, homebrew: JSON.parse(real) },
        },
    );
    assertEquals([update.ready, update.mutate, update.outcome], [
        true,
        true,
        "applied",
    ]);
});

Deno.test("Linux casks need a build for x86_64 Linux in the resolved record", () => {
    for (
        const changes of [
            { depends_on: { macos: { ">=": ["13"] } } },
            { depends_on: { maximum_macos: "sonoma" } },
            { depends_on: { arch: [{ type: "arm", bits: 64 }] } },
            { supported_platforms: ["sequoia", "arm64_sequoia"] },
        ]
    ) {
        assertEquals(
            homebrewBlock(write(), candidate(changes), true),
            "unsupported_artifact",
        );
    }
    for (
        const changes of [
            { depends_on: { arch: [{ type: "intel", bits: 64 }] } },
            { supported_platforms: ["sequoia", "x86_64_linux"] },
        ]
    ) {
        assertEquals(homebrewBlock(write(), candidate(changes), true), null);
    }
});

Deno.test("Linux cask wrappers stay under the Homebrew prefix for install and removal", () => {
    const removal = RemoveInput.parse({ ...base, installed_version: version });
    for (
        const target of [
            "/home/orbit/bin/agy",
            "~/bin/agy",
            "$HOME/bin/agy",
            "/usr/bin/agy",
        ]
    ) {
        const artifacts = [{ ...cask.artifacts[0], target }];
        assertEquals(
            homebrewBlock(write(), candidate({ artifacts }), true),
            "authorization_required",
        );
        assertEquals(
            removalDecision(removal, {
                facts: {
                    ...facts([version]),
                    homebrew: { ...cask, artifacts },
                },
            }).error_code,
            "tool.remove_failed",
        );
    }
    assertEquals(
        homebrewBlock(
            write(),
            candidate({
                artifacts: [{
                    ...cask.artifacts[0],
                    target: "$HOMEBREW_PREFIX/bin/agy",
                }],
            }),
            true,
        ),
        null,
    );
    // Other artifacts keep their home targets.
    assertEquals(
        homebrewBlock(
            write(),
            candidate({
                artifacts: [{ binary: ["agy", { target: "~/bin/agy" }] }],
            }),
            true,
        ),
        null,
    );
});

Deno.test("Linux cask commands refresh the cask API, and Linux formulae do not", () => {
    const flag = "HOMEBREW_FORCE_API_AUTO_UPDATE=1";
    const c = commands({ ...write(), kind: "tool.install" });
    // `info` reads the cached API; the auto-update command `outdated` refreshes it before the candidate read.
    assertEquals(c.refresh.slice(-4), [
        "/home/linuxbrew/.linuxbrew/bin/brew",
        "outdated",
        "--cask",
        "--quiet",
    ]);
    assertEquals(c.refresh.includes(flag), true);
    for (const argv of [c.write, c.candidate]) {
        assertEquals(argv.includes(flag), true);
        assertEquals(argv.includes("update"), false);
    }
    assertEquals(
        commands({ ...write({ kind: "tool.update" }), kind: "tool.update" })
            .write
            .includes(flag),
        true,
    );
    const formula = commands({
        ...write({ manager: "brew", package: "hello" }),
    });
    assertEquals(
        formula.write.includes(flag) || formula.candidate.includes(flag),
        false,
    );
    assertEquals(formula.refresh.includes("outdated"), false);
});

Deno.test("Linux casks update to a newer build and stay unchanged on the same one", () => {
    const update = write({
        kind: "tool.update",
        tool_id: 1,
        version_constraint: "^1.5",
    });
    const newer = "1.5.14,6000000000000000";
    const applied = decide(update, {
        facts: facts([version]),
        candidate: candidate({ version: newer }),
    });
    assertEquals([applied.mutate, applied.outcome, applied.installed_version], [
        true,
        "applied",
        "1.5.13",
    ]);
    // A new build of the same SemVer is still an update.
    assertEquals(
        decide(update, {
            facts: facts([version]),
            candidate: candidate({ version: "1.5.13,6000000000000000" }),
        }).mutate,
        true,
    );
    assertEquals(
        decide(update, { facts: facts([version]), candidate: candidate() })
            .outcome,
        "unchanged",
    );
});

Deno.test("Linux casks record an installed comma-build version against the constraint", () => {
    const retry = decide(
        write({
            tool_id: 1,
            tool_status: "failed",
            version_constraint: "^1.5",
        }),
        { facts: facts([version]) },
    );
    assertEquals([retry.ready, retry.mutate, retry.installed_version], [
        true,
        false,
        "1.5.13",
    ]);
    const refused = decide(
        write({
            tool_id: 1,
            tool_status: "failed",
            version_constraint: "^2.0",
        }),
        { facts: facts([version]) },
    );
    // A refusal after the installed read reports the same public version as a successful read.
    assertEquals([refused.installed_version, refused.installed_coordinate], [
        "1.5.13",
        version,
    ]);
    assertEquals(
        refused.error_code,
        "tool.installed_version_constraint_violated",
    );
});

Deno.test("Linux casks remove the command_wrapper cask and read it from the Linux prefix", () => {
    const input = RemoveInput.parse({ ...base, installed_version: version });
    assertEquals(removalDecision(input, { facts: facts([version]) }), {
        ready: true,
        remove: true,
        manager_version: "Homebrew 5.0.0",
    });
    const read: Record<string, unknown> = inventory(
        { ...input, user: "orbit" },
        {
            status: "succeeded",
            outputs: { stdout: "Homebrew 5.0.0\n", exitCode: 0 },
        },
        {
            status: "succeeded",
            outputs: {
                stdout: JSON.stringify({
                    formulae: [],
                    casks: [{ ...cask, installed: version }],
                }),
                exitCode: 0,
            },
        },
    );
    assertEquals([read.versions, read.prefix, read.os_major], [
        [version],
        prefix,
        0,
    ]);
});

Deno.test("the Linux cask version read after a write reports the SemVer without the build", async () => {
    const observed = await behavior.methods.observe.execute({
        input: write({ version_constraint: "^1.5" }),
        after: {
            exitCode: 0,
            stdout: JSON.stringify({
                formulae: [],
                casks: [{ ...cask, installed: version }],
            }),
        },
    }, {
        extensionFile: (p: string) => new URL(p, import.meta.url).pathname,
        writeResource: () => Promise.resolve(null),
    });
    assertEquals([observed.installed_version, observed.present], [
        "1.5.13",
        true,
    ]);
});

Deno.test("Linux arm64 has no cask tag and the build is dropped only for casks", () => {
    assertThrows(
        () =>
            homebrewBlock(write({ architecture: "arm64" }), candidate(), true),
        ToolRefusal,
        "tool.version_probe_failed",
    );
    assertEquals(normalizeVersion(version, "brew-cask"), "1.5.13");
    for (
        const raw of [
            ",5999302598852608",
            version + "\n",
            version + "1".repeat(233),
        ]
    ) {
        assertEquals(normalizeVersion(raw, "brew-cask"), null);
    }
    for (const manager of ["brew", "apt", "vp", "composer"] as const) {
        assertEquals(normalizeVersion(version, manager), null);
    }
});

const ok = (stdout: string, stderr = "", exitCode = 0) => ({
    status: "succeeded",
    outputs: { stdout, stderr, exitCode },
});
const ctx = {
    extensionFile: (p: string) => new URL(p, import.meta.url).pathname,
    writeResource: () => Promise.resolve(null),
};

Deno.test("a failed or stale Linux cask API refresh stops the candidate read", async () => {
    const run = (refresh: ReturnType<typeof ok> | null) =>
        behavior.methods.decide.execute({
            input: write({ kind: "tool.update", tool_id: 1 }),
            version: ok("Homebrew 7.0.13\n"),
            query: ok(JSON.stringify({
                formulae: [],
                casks: [{ ...cask, installed: version }],
            })),
            os: null,
            check: ok(JSON.stringify({
                formulae: [],
                casks: [{ ...cask, version: "1.5.13,6000000000000000" }],
            })),
            refresh,
        }, ctx);
    const fresh = await run(ok(
        "",
        "==> Downloading Homebrew API data\nJSON API packages.x86_64_linux.jws.json\n",
    ));
    assertEquals([fresh.mutate, fresh.outcome, fresh.installed_coordinate], [
        true,
        "applied",
        version,
    ]);
    assertEquals((await run(ok(""))).mutate, true);
    for (
        const refresh of [
            ok(
                "",
                "Warning: packages.x86_64_linux.jws.json: update failed, falling back to cached version.\n",
            ),
            ok("", "", 1),
            null,
        ]
    ) {
        const stale = await run(refresh);
        assertEquals([stale.mutate, stale.error_code], [
            false,
            "tool.candidate_version_probe_failed",
        ]);
    }
});

Deno.test("the post-write read keeps the raw cask coordinate beside the public version", async () => {
    const observed = await behavior.methods.observe.execute({
        input: write(),
        after: {
            exitCode: 0,
            stdout: JSON.stringify({
                formulae: [],
                casks: [{ ...cask, installed: version }],
            }),
        },
    }, ctx);
    assertEquals([observed.installed_version, observed.installed_coordinate], [
        "1.5.13",
        version,
    ]);
});

Deno.test("only comma-bearing cask versions change their public form", () => {
    assertEquals(publicVersion(version, "brew-cask"), "1.5.13");
    for (const raw of ["140.0", "v1.2", "3.003", "latest"]) {
        assertEquals(publicVersion(raw, "brew-cask"), raw);
    }
    for (const manager of ["brew", "apt", "vp", "composer"] as const) {
        assertEquals(publicVersion(version, manager), version);
    }
});
