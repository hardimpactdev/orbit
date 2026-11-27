import { assertEquals } from "jsr:@std/assert@1";
import { behavior, removalDecision, RemoveInput } from "./remove.ts";
import * as native from "./native.ts";
import { preferences } from "./native.ts";
const probe = (
    input: ReturnType<typeof fixture>["input"],
    facts: unknown,
    plan: unknown = { packages: ["hello"] },
) => removalDecision(input, { facts, plan });
function fixture(manager = "apt") {
    const input = RemoveInput.parse({
        node_id: 9,
        manager,
        package: manager === "composer" ? "vendor/package" : "hello",
        user: "orbit",
        platform: manager === "brew-cask" ? "macos" : "linux",
        architecture: "x86_64",
        installed_version: "1.0",
        package_owned: true,
    });
    const facts: Record<string, unknown> = {
        scope: "present",
        manager_version: "3.0",
        versions: ["1.0"],
        complete: true,
        roots: [input.package],
        os_major: 15,
        prefix: "/prefix",
        home: "/home/orbit",
        scope_path: "/opt/orbit/vite-plus",
        token_count: 1,
    };
    if (manager === "brew-cask") {
        facts.homebrew = {
            token: "hello",
            tap: "homebrew/cask",
            artifacts: [{ font: ["Font.ttf"] }, {
                uninstall: [{ delete: "~/Library/Fonts/Font.ttf" }],
            }],
        };
    }
    return { input, facts };
}
const ctx = {
    extensionFile: (name: string) => new URL(name, import.meta.url).pathname,
    writeResource: () => Promise.resolve(null),
};
Deno.test("removal requires complete selected inventory for each manager, including delisted casks", () => {
    for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
        const { input, facts } = fixture(manager);
        assertEquals(probe(input, facts).remove, true);
        facts.versions = [];
        assertEquals(probe(input, facts, null), {
            ready: true,
            remove: false,
            manager_version: "3.0",
        });
        for (
            const patch of [
                { complete: false },
                { versions: [null] },
                { versions: ["1", "2"] },
                { manager_version: "" },
                { scope: "absent" },
                { error_code: "tool.version_probe_failed" },
            ]
        ) {
            assertEquals(probe(input, { ...facts, ...patch }).ready, false);
        }
    }
    const { input, facts } = fixture("composer");
    facts.roots = [];
    assertEquals(probe(input, facts).error_code, "tool.remove_failed");
    assertEquals(
        removalDecision(input, null).error_code,
        "tool.version_probe_failed",
    );
});
Deno.test("cask removal refuses ambiguous tokens, arbitrary uninstall scripts and privileged paths", () => {
    const { input, facts } = fixture("brew-cask");
    for (
        const patch of [{ token_count: 2 }, { token_count: 0 }, {
            homebrew: { token: "hello", tap: "foreign/tap", artifacts: [] },
        }]
    ) {
        assertEquals(probe(input, { ...facts, ...patch }).ready, false);
    }
    for (
        const artifacts of [[{ pkg: ["Installer.pkg"] }], [{
            app: ["Hello.app"],
        }], [{ binary: ["hello", { target: "/usr/bin/hello" }] }]]
    ) {
        assertEquals(
            probe(input, {
                ...facts,
                homebrew: { token: "hello", tap: "homebrew/cask", artifacts },
            }).ready,
            false,
        );
    }
    for (
        const uninstall of [
            { script: "anything" },
            { pkgutil: "example.test" },
            { delete: "/Library/Fonts/Font.ttf" },
            { rmdir: "../foreign" },
        ]
    ) {
        assertEquals(
            probe(input, {
                ...facts,
                homebrew: {
                    token: "hello",
                    tap: "homebrew/cask",
                    artifacts: [{ uninstall: [uninstall] }],
                },
            }).ready,
            false,
        );
    }
});
Deno.test("a package Orbit does not own is never removed, even when it is a removable root", () => {
    for (const manager of ["apt", "composer"]) {
        const { input, facts } = fixture(manager);
        assertEquals(probe(input, facts).remove, true);
        const unowned = RemoveInput.parse({ ...input, package_owned: false });
        const cleanup = { ready: true, remove: false, manager_version: null };
        assertEquals(probe(unowned, facts), cleanup);
        assertEquals(
            probe(unowned, facts, { packages: ["hello", "other"] }),
            cleanup,
        );
        // An unknown package fails the removal simulation and its facts may be unreadable; neither stops the source cleanup.
        assertEquals(removalDecision(unowned, null), cleanup);
        // A request queued before the ownership contract carries no ownership: it never removes the package.
        const { package_owned: _owned, ...queued } = input;
        assertEquals(probe(RemoveInput.parse(queued), facts), cleanup);
    }
});

Deno.test("apt eligibility refuses empty, unread or wider plans", () => {
    const { input, facts } = fixture("apt");
    for (const packages of [[], ["hello", "shared"], ["other"]]) {
        assertEquals(
            probe(input, facts, { packages }).error_code,
            "tool.removal_plan_unsafe",
        );
    }
    assertEquals(probe(input, facts, null).error_code, "tool.remove_failed");
});
Deno.test("brew refuses while another installed formula depends on it", () => {
    const { input, facts } = fixture("brew");
    assertEquals(
        probe(input, facts, { packages: ["hello", "other"] }).error_code,
        "tool.removal_plan_unsafe",
    );
    assertEquals(probe(input, facts, { packages: ["hello"] }).remove, true);
});
Deno.test("removals use one manager argv with no shipped code", async () => {
    for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
        const value = await behavior.methods.assets.execute({
            input: fixture(manager).input,
        }, ctx);
        assertEquals(value.remove.includes("-c"), false);
        assertEquals(value.plan.includes("-c"), false);
    }
});
Deno.test("an Orbit source always restricts every other package of all its origins, and its held preferences drop an unowned acting package", () => {
    const repository = {
        name: "proof",
        uris: ["https://vendor.example.test/apt"],
        suites: ["stable"],
        components: ["main"],
        signing_key: {
            url: "https://vendor.example.test/key.asc",
            fingerprint: "A".repeat(40),
        },
    };
    const wildcard =
        'Package: *\nPin: origin "vendor.example.test"\nPin-Priority: 1\n';
    assertEquals(preferences(repository, []), wildcard);
    const commands = native.commands({
        manager: "apt",
        package: "tree",
        platform: "linux",
        architecture: "x86_64",
        user: "orbit",
        repository,
        repository_packages: ["nano", "tree"],
        package_owned: false,
    });
    assertEquals(
        commands.repository_preferences,
        preferences(repository, ["nano", "tree"]),
    );
    assertEquals(
        commands.repository_preferences_held,
        preferences(repository, ["nano"]),
    );
    // Each origin of the repository gets the same pin: its owned packages at 990, every other package at 1.
    const twoOrigins = { ...repository, uris: ["https://vendor.example.test/apt", "https://mirror.example.test/apt"] };
    assertEquals(
        preferences(twoOrigins, ["nano"]),
        'Package: nano\nPin: origin "vendor.example.test"\nPin-Priority: 990\n\n' + wildcard + "\n" +
            'Package: nano\nPin: origin "mirror.example.test"\nPin-Priority: 990\n\n' +
            'Package: *\nPin: origin "mirror.example.test"\nPin-Priority: 1\n',
    );
    // An owned package keeps its pin before the write too.
    const owned = native.commands({
        manager: "apt", package: "tree", platform: "linux", architecture: "x86_64", user: "orbit", repository,
        repository_packages: ["nano", "tree"], package_owned: true,
    });
    assertEquals(owned.repository_preferences, preferences(repository, ["nano", "tree"]));
    assertEquals(owned.repository_preferences_held, preferences(repository, ["nano", "tree"]));
});

Deno.test("an IPv6 source origin is written as apt matches it, without brackets, and the contract accepts it", () => {
    const repository = { name: "proof", uris: ["https://[2001:DB8::7]/apt"], suites: ["stable"], components: ["main"],
        signing_key: { url: "https://[2001:db8::7]/key.asc", fingerprint: "A".repeat(40) } };
    assertEquals(preferences(repository, []), 'Package: *\nPin: origin "2001:db8::7"\nPin-Priority: 1\n');
    // apt reads the literal as written: an uncompressed one is not shortened to ::1.
    assertEquals(native.origin("https://[0:0:0:0:0:0:0:1]/apt"), "0:0:0:0:0:0:0:1");
    assertEquals(native.origin("https://Packages.Example.test:8443/apt"), "packages.example.test");
    const input = RemoveInput.parse({ node_id: 7, manager: "apt", package: "tree", platform: "linux", architecture: "x86_64", user: "orbit", repository });
    assertEquals(input.repository?.uris, ["https://[2001:DB8::7]/apt"]);
});

Deno.test("a removal's check after its package step reads whether the package survived, whatever version an install would target", async () => {
    const { behavior: write } = await import("./write.ts");
    const input = { ...fixture().input, desired_version: "3.0" };
    const after = { status: "compliant", packages: [{ name: "hello", installed: true, version: "1.0" }] };
    const context = { extensionFile: (p: string) => p, writeResource: () => Promise.resolve(null) };
    const survived = await write.methods.observe.execute({ input, after, removal: true }, context);
    assertEquals([survived.present, survived.absent, survived.installed_version], [true, false, "1.0"]);
    // An install's end check still holds the version to its target.
    await write.methods.observe.execute({ input, after }, context).then(
        () => { throw new Error("an install end check accepted a version outside its target"); },
        (error: { code?: string }) => assertEquals(error.code, "tool.installed_version_mismatch"),
    );
});
