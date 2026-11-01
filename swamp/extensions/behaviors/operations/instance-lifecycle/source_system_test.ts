import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { runLocalSource } from "./source_system.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

async function git(directory: string, ...args: string[]) {
    const value = await new Deno.Command("/usr/bin/git", {
        args: ["-c", "core.hooksPath=/dev/null", "-C", directory, ...args],
        env: { GIT_CONFIG_NOSYSTEM: "1", GIT_CONFIG_GLOBAL: "/dev/null" },
        stdout: "piped",
        stderr: "piped",
    }).output();
    assert(value.success, new TextDecoder().decode(value.stderr));
    return new TextDecoder().decode(value.stdout).trimEnd();
}
async function identity(flag: string) {
    const value = await new Deno.Command("/usr/bin/id", {
        args: [flag],
        stdout: "piped",
    }).output();
    return new TextDecoder().decode(value.stdout).trim();
}
async function fixture() {
    const root = await Deno.makeTempDir({ prefix: "orbit-source-local-" });
    const repository = `${root}/origin`,
        checkout = `${root}/apps/proof/feature`;
    await Deno.mkdir(repository);
    await git(repository, "init", "--quiet", "--initial-branch=main");
    await git(repository, "config", "user.name", "Orbit fixture");
    await git(repository, "config", "user.email", "fixture@example.invalid");
    await Deno.writeTextFile(`${repository}/README.md`, "source\n");
    await Deno.writeTextFile(
        `${repository}/.env.example`,
        "APP_NAME=example\n",
    );
    await git(repository, "add", ".");
    await git(repository, "commit", "--quiet", "-m", "initial");
    const user = await identity("-un"), group = await identity("-gn");
    assert(
        user !== "root",
        "Run source ownership tests as a non-root Node account",
    );
    const source = {
        repository,
        checkout,
        root: `${root}/apps`,
        user,
        group,
        prepare_id: "fixture-attempt",
        seed_repository: null,
    };
    const call = (
        name: string,
        input: Record<string, unknown>,
        options: Partial<NodeCall> = {},
    ) => runLocalSource({
        name,
        input,
        pin: "a".repeat(64),
        sudo: false,
        mutates: true,
        deadlineMs: 30000,
        ...options,
    });
    const prepare = () =>
        call("instance.prepare-development-source", {
            ...source,
            allow_existing: true,
            network: true,
            seed_commit: null,
            git_read: { github_token: null },
        });
    const resolve = (extra: Record<string, unknown> = {}) =>
        call("instance.resolve-development-source", {
            ...source,
            instance_name: "feature",
            default_branch: "main",
            branch_override: null,
            seed_commit: null,
            network: true,
            protocols: ["file"],
            git_read: { github_token: null },
            ...extra,
        });
    return {
        root,
        repository,
        checkout,
        user,
        group,
        source,
        call,
        prepare,
        resolve,
        cleanup: () => Deno.remove(root, { recursive: true }),
    };
}

Deno.test("local source prepares, resolves, replays and reads only the committed template", async () => {
    const f = await fixture();
    try {
        assertEquals(await f.prepare(), { created: true });
        const resolved = await f.resolve();
        assertEquals(resolved.branch, "feature");
        assertMatch(String(resolved.commit), /^[a-f0-9]{40}$/);
        assertEquals(await f.prepare(), { created: false });
        assertEquals(
            await f.call("instance.inspect-development-source", {
                ...f.source,
                verify_origin: true,
                require_idle: true,
            }),
            {
                origin: f.repository,
                branch: "feature",
                commit: resolved.commit,
            },
        );
        await Deno.writeTextFile(
            `${f.checkout}/.env.example`,
            "UNTRACKED_CHANGE=not_the_template\n",
        );
        await Deno.writeTextFile(`${f.checkout}/.env`, "SECRET=never_read\n");
        assertEquals(
            await f.call("instance.read-development-environment-template", {
                ...f.source,
                application: f.checkout,
                commit: resolved.commit,
            }),
            { environment_template: "APP_NAME=example\n" },
        );
        assertEquals(
            await f.call("instance.inspect-development-profile", {
                application: f.checkout,
                owner: f.user,
                entry_point: "artisan",
            }),
            { kind: "none", composer: null, entry_point: null },
        );
        await Deno.writeTextFile(
            `${f.checkout}/composer.json`,
            '{"require":{"php":"^8.4"}}',
        );
        await Deno.writeTextFile(`${f.checkout}/artisan`, "<?php\n");
        const profile = await f.call("instance.inspect-development-profile", {
            application: f.checkout,
            owner: f.user,
            entry_point: "artisan",
        });
        assertEquals(profile.kind, "composer");
        assertEquals(profile.entry_point, "regular");
        assertEquals(
            atob(String(profile.composer)),
            '{"require":{"php":"^8.4"}}',
        );
    } finally {
        await f.cleanup();
    }
});

Deno.test("local source refuses a symlink parent and a replaced prepare receipt", async () => {
    const f = await fixture();
    try {
        await Deno.mkdir(`${f.root}/outside`);
        await Deno.mkdir(f.source.root);
        await Deno.symlink(`${f.root}/outside`, `${f.source.root}/proof`);
        assertEquals(await f.prepare(), {
            error_code: "instance.source_invalid",
        });
        const outside = [];
        for await (const entry of Deno.readDir(`${f.root}/outside`)) {
            outside.push(entry);
        }
        assertEquals(outside, []);
        await Deno.remove(`${f.source.root}/proof`);
        await f.prepare();
        await Deno.writeTextFile(
            `${f.checkout}/.git/orbit-source-prepare`,
            "different:1:2\n",
        );
        assertEquals(await f.prepare(), {
            error_code: "instance.source_invalid",
        });
    } finally {
        await f.cleanup();
    }
});

Deno.test("local source refuses repository filter execution and a symlink template", async () => {
    const f = await fixture();
    try {
        await f.prepare();
        await git(f.checkout, "config", "filter.evil.smudge", "touch sentinel");
        assertEquals(await f.resolve(), {
            error_code: "instance.source_metadata_unsafe",
        });
        await git(f.checkout, "config", "--unset", "filter.evil.smudge");
        await Deno.remove(`${f.repository}/.env.example`);
        await Deno.symlink("README.md", `${f.repository}/.env.example`);
        await git(f.repository, "add", ".env.example");
        await git(f.repository, "commit", "--quiet", "-m", "linked template");
        const resolved = await f.resolve();
        assertEquals(
            await f.call("instance.read-development-environment-template", {
                ...f.source,
                application: f.checkout,
                commit: resolved.commit,
            }),
            { error_code: "instance.source_invalid" },
        );
    } finally {
        await f.cleanup();
    }
});

Deno.test("closed source dispatcher refuses unsupported and production actions before changing paths", async () => {
    const f = await fixture();
    try {
        assertEquals(
            await f.call("instance.prepare-production-user", {
                user: f.user,
                home: `${f.root}/home`,
            }, { sudo: true }),
            { error_code: "instance.source_kind_unavailable" },
        );
        assertEquals(
            await f.call("run-arbitrary", {
                program: "touch",
                path: `${f.root}/marker`,
            }),
            { error_code: "instance.source_kind_unavailable" },
        );
        assertEquals(await f.call("account.lookup", { user: "nobody" }), {
            error_code: "instance.account_mismatch",
        });
        let exists = true;
        try {
            await Deno.lstat(`${f.root}/home`);
        } catch {
            exists = false;
        }
        assertEquals(exists, false);
        const token = "ghs_private_sentinel";
        const reply = await f.call("instance.prepare-development-source", {
            ...f.source,
            allow_existing: true,
            network: true,
            seed_commit: null,
            git_read: { github_token: token },
        });
        assertEquals(reply, { error_code: "instance.source_git_failed" });
        assert(!JSON.stringify(reply).includes(token));
    } finally {
        await f.cleanup();
    }
});
