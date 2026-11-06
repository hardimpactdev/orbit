import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { toolSetupPlan } from "./tool_setup_plan.ts";
import { Setup } from "./tool_setup_input.ts";
const pin = {
    version: "1.0.0",
    urls: ["https://example.invalid/artifact.tar.gz"],
    algorithm: "sha256",
    digest: "a".repeat(64),
};
const composer = {
    manager: "composer",
    input: { user: "orbit", version: "2.9.5-1" },
    apt: {
        snapshot: "20260101T000000Z",
        packages: [{ name: "composer", version: "2.9.5-1", artifact: null }],
    },
    packages: [],
};
Deno.test("Composer setup preserves its scope and installs exact snapshot packages without removing others", async () => {
    const plan = await toolSetupPlan(composer, "/home/orbit");
    assertEquals(
        plan.directories.some((d) =>
            d.path === "/opt/orbit/composer/vendor/bin" && d.owner === "orbit"
        ),
        true,
    );
    assertEquals(
        plan.files.some((f) => f.path === "/opt/orbit/composer/composer.json"),
        false,
    );
    assertEquals(plan.apt_install.includes("--no-remove"), true);
    assertEquals(plan.apt_install.at(-1), "composer=2.9.5-1");
    assertEquals(
        plan.apt_update.includes("APT::Get::List-Cleanup=false"),
        true,
    );
    assertEquals(
        plan.files.some((f) =>
            String(f.content).includes(
                "snapshot.ubuntu.com/ubuntu/20260101T000000Z/",
            )
        ),
        true,
    );
});
Deno.test("Composer setup reads the snapshot over https by itself and through a package cache over http", async () => {
    const direct = await toolSetupPlan(composer, "/home/orbit");
    const content = (plan: typeof direct) =>
        plan.files.filter((f) => String(f.path).endsWith("/sources.list")).map((
            f,
        ) => String(f.content)).join("");
    assertEquals(
        content(direct).includes("] https://snapshot.ubuntu.com/"),
        true,
    );
    assertEquals(
        direct.apt_update.some((a) => a.startsWith("Acquire::http::Proxy")),
        false,
    );
    const cached = await toolSetupPlan({
        ...composer,
        apt: { ...composer.apt, proxy: "http://10.232.2.1:3142" },
    }, "/home/orbit");
    assertEquals(
        content(cached).includes("] http://snapshot.ubuntu.com/"),
        true,
    );
    assertEquals(content(cached).includes("https://"), false);
    for (const argv of [cached.apt_update, cached.apt_install_prefix]) {
        assertEquals(
            argv.includes("Acquire::http::Proxy=http://10.232.2.1:3142"),
            true,
        );
        assertEquals(argv.includes("Dir::Etc::parts=-"), true);
        assertEquals(argv.includes("Acquire::Retries=5"), true);
    }
    assertEquals(direct.apt_update.includes("Dir::Etc::parts=-"), false);
});
Deno.test("A package cache of another kind than plain http is refused", () => {
    for (
        const proxy of [
            "https://10.232.2.1:3142",
            "10.232.2.1:3142",
            "ftp://10.232.2.1",
        ]
    ) {
        assertEquals(
            Setup.safeParse({ ...composer, apt: { ...composer.apt, proxy } })
                .success,
            false,
            proxy,
        );
    }
    assertEquals(
        Setup.safeParse({
            ...composer,
            apt: { ...composer.apt, proxy: "http://10.232.2.1:3142" },
        }).success,
        true,
    );
    assertEquals(Setup.safeParse(composer).success, true);
});
Deno.test("Homebrew setup describes one pinned repository and retains other package managers", async () => {
    const plan = await toolSetupPlan({
        manager: "homebrew",
        input: { user: "orbit", revision: "c".repeat(40), version: "7.0.0" },
        apt: null,
        packages: [],
    }, "/home/orbit");
    assertEquals(
        plan.links.some((l) =>
            l.path === "/home/linuxbrew/.linuxbrew/bin/brew"
        ),
        true,
    );
    assertEquals(plan.sha256_fetches, []);
    assertEquals(plan.apt_install, []);
});
Deno.test("JS setup verifies the lock before exposing paths and keeps SHA512 checks separate from extraction", async () => {
    const lock = JSON.stringify({
        name: "orbit-vp",
        version: "1.0.0",
        lockfileVersion: 3,
    });
    const digest = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(lock),
            ),
        ),
        (n) => n.toString(16).padStart(2, "0"),
    ).join("");
    const setup = {
        manager: "javascript",
        input: {
            user: "orbit",
            tools: {
                node: { ...pin, urls: ["https://example.invalid/node.tar.xz"] },
                "vp-cli": pin,
                pnpm: { ...pin, algorithm: "sha512", digest: "b".repeat(128) },
                vp: { ...pin, lock_sha256: digest },
            },
            vp_lock: lock,
        },
        apt: null,
        packages: [],
    };
    const plan = await toolSetupPlan(setup, "/home/orbit");
    assertEquals(plan.sha512.length, 1);
    assertEquals(
        plan.sha512[0].stdin,
        "b".repeat(128) +
            "  /var/cache/orbit/tool-setup/javascript/pnpm.tar.gz\n",
    );
    assertEquals(
        plan.archives.find((a) => String(a.source).includes("node.tar.xz"))
            ?.format,
        "tar.xz",
    );
    assertEquals(
        plan.commands.some((c) =>
            (c.argv as string[]).includes("--ignore-scripts")
        ),
        true,
    );
    assertEquals(
        plan.launchers.some((f) =>
            f.path === "/usr/local/bin/node" &&
            String(f.content).includes(
                'exec "/home/orbit/.local/share/vite-plus/bin/node"',
            )
        ),
        true,
    );
    await assertRejects(
        () =>
            toolSetupPlan({
                ...setup,
                input: { ...setup.input, vp_lock: lock + " " },
            }, "/home/orbit"),
        Error,
        "node.tool_lock_mismatch",
    );
    assertEquals(
        Setup.safeParse({ ...setup, input: { ...setup.input, user: "root" } })
            .success,
        false,
    );
});

Deno.test("Composer plan preserves mirror then upstream candidates and their checksum", async () => {
    const urls = [
        "https://mirror.invalid/git.deb",
        "https://upstream.invalid/git.deb",
    ];
    const plan = await toolSetupPlan({
        ...composer,
        apt: {
            ...composer.apt,
            packages: [{
                name: "git",
                version: "2.55.0",
                artifact: {
                    urls,
                    algorithm: "sha256",
                    digest: "a".repeat(64),
                },
            }],
        },
    }, "/home/orbit");
    assertEquals(plan.sha256_fetches[0].urls, urls);
    assertEquals(plan.sha256_fetches[0].checksum, "sha256:" + "a".repeat(64));
});
