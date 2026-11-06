import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import { teardownArgv } from "./assets.ts";
import { ciCacheImportProgram } from "../instance-deploy/app_step_runtime.ts";
import {
    bindHostKeys,
    connection,
    dependencyToken,
    Intent,
    observe,
    pinIntents,
    result,
    revokeOutcome,
    setupArgv,
    sourceResult,
} from "./provision.ts";

const intent = {
    task_vm_id: 10,
    guest_node_id: 20,
    group_id: 30,
    instance_id: 40,
    project_id: 50,
    wireguard_ip: "10.44.0.20",
    ssh_identity: {
        control_node_id: 209,
        identity_file: "/run/credentials/key",
        public_key: "ssh-ed25519 PUBLIC",
    },
    workspace: {
        checkout_path: "/home/orbit/apps/project/workspace",
        repository: "https://github.com/acme/test.git",
        repository_identity: "github:acme/test",
        branch: "task-30",
        default_branch: "main",
        start_commit: "c".repeat(40),
        user: "orbit",
        project_type: "monorepo",
        entry_point: "artisan",
        skip_profile: true,
        php_versions: ["8.5"],
        setup: [],
    },
};
const vm = {
    task_vm_id: 10,
    guest_node_id: 20,
    ready: true,
    provider: { address: "192.0.2.20" },
    enrollment: {
        ready: true,
        node: {
            ssh_host_key_type: "ssh-ed25519",
            ssh_host_key: "PUBLIC",
            observer_digest: "public-digest",
        },
        process: { process_id: 60, status: "active" },
    },
};
Deno.test("provision source uses the trusted child pin with captured WG/account and original identities", () => {
    const workspace = connection(intent, vm);
    assertEquals(workspace.task_vm_id, 10);
    assertEquals(workspace.checkout_path, intent.workspace.checkout_path);
    assertEquals(workspace.ssh_execution.target, {
        name: "target",
        node_id: 20,
        host: "10.44.0.20",
        port: 22,
        user: "orbit",
        host_keys: ["ssh-ed25519 PUBLIC"],
    });
    assertThrows(() => connection(intent, { ...vm, guest_node_id: 99 }));
});
Deno.test("a failed source step retains VM readiness but never supplies successful source evidence", () => {
    const receipt = result(intent, vm, {
        guest: { status: "succeeded" },
        prepare: { status: "failed" },
    });
    assertEquals(receipt.ready, true);
    assertEquals(receipt.error_code, "tasks.provision_failed");
    assertEquals(receipt.failed_step, "prepare");
    assertEquals("source" in receipt, false);
});
Deno.test("wrong child identity is never retained as confirmed VM readiness", () => {
    const receipt = result(intent, { ...vm, task_vm_id: 99 }, {
        guest: { status: "failed" },
    });
    assertEquals("ready" in receipt, false);
    assertEquals(receipt.failed_step, "guest");
});
Deno.test("source result requires every ordered setup step before exposing the source receipt", () => {
    const input = {
        ...intent,
        workspace: {
            ...intent.workspace,
            setup: [{
                name: "setup",
                command: "true",
                timeout_seconds: 30,
                input_json: '{"command":"true"}',
            }],
        },
    };
    const source = {
        branch: "task-30",
        commit: "a".repeat(40),
        php_version: null,
        laravel: false,
    };
    const steps = Object.fromEntries(
        [
            "prepare",
            "directory",
            "control",
            "mint_clone",
            "clone",
            "bundle",
            "prepare_remote",
            "copy_bundle",
            "import",
            "origin",
            "branch",
            "identity",
            "inspect",
            "composer",
            "profile",
            "deps_write",
            "deps_remove",
        ].map((name) => [name, { status: "succeeded", outputs: {} }]),
    );
    assertEquals(sourceResult(input, steps), {
        error_code: "tasks.provision_failed",
        failed_step: "setup-0",
    });
    // A guard skips the composer copy without a regular composer.json; a failed copy fails.
    steps.composer.status = "failed";
    assertEquals(sourceResult(input, steps), {
        error_code: "tasks.provision_failed",
        failed_step: "composer",
    });
    steps.profile.outputs = { source };
    delete steps.composer;
    steps["setup-0"] = { status: "succeeded", outputs: {} };
    assertEquals(sourceResult(input, steps), {
        source,
    });
    // The private-dependency steps are part of the receipt: a failed mint, write or removal fails the provision.
    for (const name of ["deps_write", "deps_remove"]) {
        const before = steps[name].status;
        steps[name].status = "failed";
        assertEquals(sourceResult(input, steps), { error_code: "tasks.provision_failed", failed_step: name });
        steps[name].status = before;
        // A skipped step has no entry: a Project without setup steps mints nothing.
        const kept = steps[name];
        delete steps[name];
        assertEquals(sourceResult(input, steps), { source });
        steps[name] = kept;
    }
    const receipt = result(input, vm, {
        guest: { status: "succeeded" },
        ...steps,
    });
    assertEquals("result_json" in receipt, false);
});

Deno.test("proof provision requires private delivery before group readiness but preserves observed VM facts on refusal", () => {
    const input = { ...intent, project_id: 62, proof_club_required: true };
    const steps: Record<string, { status: string; outputs?: unknown }> = {
        guest: { status: "succeeded" },
        ...Object.fromEntries(
            [
                "prepare",
                "directory",
                "control",
                "mint_clone",
                "clone",
                "bundle",
                "prepare_remote",
                "copy_bundle",
                "import",
                "origin",
                "branch",
                "identity",
                "inspect",
                "composer",
                "deps_write",
                "deps_remove",
            ].map((name) => [name, { status: "succeeded", outputs: {} }]),
        ),
        profile: {
            status: "succeeded",
            outputs: {
                source: {
                    branch: "task-30",
                    commit: "a".repeat(40),
                    php_version: null,
                    laravel: false,
                },
            },
        },
        proof_club: { status: "failed" },
    };
    const refused = result(input, vm, steps);
    assertEquals(refused.failed_step, "proof_club");
    assertEquals(refused.ready, true); // VM facts only; error prevents successful group/source projection.
    assertEquals("source" in refused, false);
    steps.proof_club.status = "succeeded";
    assertEquals(result(input, vm, steps).error_code, undefined);
});

Deno.test("profile refuses a checkout that is not on the admitted start commit", async () => {
    const { behavior, Intent } = await import("./provision.ts");
    const admitted = Intent.parse(intent);
    const ctx = {
        extensionFile: () => "unused",
        writeResource: () => Promise.resolve({}),
    };
    const read = (commit: string) => ({
        identity: commit + "\ntask-30\n",
        files: "",
        composer: "/nonexistent/composer.json",
    });
    await behavior.methods.profile.execute({
        intent: admitted,
        ...read("c".repeat(40)),
    }, ctx);
    await assertRejects(
        () =>
            behavior.methods.profile.execute({
                intent: admitted,
                ...read("d".repeat(40)),
            }, ctx),
        Error,
        "instance.source_identity_changed",
    );
});

Deno.test("profile reads and deletes the downloaded composer.json and keeps only its facts", async () => {
    const { behavior } = await import("./provision.ts");
    const directory = await Deno.makeTempDir();
    const path = directory + "/composer.json";
    const secret = ["ghp_", "C".repeat(36)].join("");
    await Deno.writeTextFile(
        path,
        JSON.stringify({
            require: { php: "^8.5", "laravel/framework": "^13" },
            extra: { token: secret },
        }),
    );
    const written: unknown[] = [];
    await behavior.methods.profile.execute({
        intent: Intent.parse({
            ...intent,
            workspace: {
                ...intent.workspace,
                project_type: "laravel-app",
                skip_profile: false,
            },
        }),
        identity: "c".repeat(40) + "\ntask-30\n",
        files: "composer.json|regular file|90\nartisan|regular file|20\n",
        composer: path,
    }, {
        extensionFile: () => "unused",
        writeResource: (_spec, _name, value) => {
            written.push(value);
            return Promise.resolve({});
        },
    });
    assertEquals(written, [{
        source: {
            branch: "task-30",
            commit: "c".repeat(40),
            php_version: "8.5",
            laravel: true,
        },
    }]);
    await assertRejects(() => Deno.stat(path), Deno.errors.NotFound);
    await Deno.remove(directory);
});

Deno.test("each setup and teardown command runs as one SSH exec argv in the checkout with empty seeds, its output in a file", () => {
    const setup = (command: string) => ({
        ...intent,
        workspace: {
            ...intent.workspace,
            setup: [{
                name: "setup",
                command,
                timeout_seconds: 30,
                input_json: JSON.stringify({ command }),
            }],
        },
    });
    const argv = (directory: string, name: string, command: string, environment: string[] = []) => [
        "/bin/bash",
        "-c",
        'mkdir -p -m 0700 -- "$1" && exec >"$1/$2" 2>&1 && shift 2 && exec "$@"',
        "orbit-output",
        directory,
        name,
        "env",
        "-C",
        "/home/orbit/apps/project/workspace",
        "COMPOSER_NO_INTERACTION=1",
        "ORBIT_CHECK_PROCESSES=1",
        "COMPOSER_CACHE_DIR=/home/orbit/.cache/composer",
        "ORBIT_SEED_PATH=",
        "ORBIT_SEED_COMMIT=",
        ...environment,
        "/bin/bash",
        "-lc",
        command,
    ];
    assertEquals(setupArgv(setup("composer install"), "/tmp/run"), [
        argv("/tmp/run/output", "setup-0", "composer install", ["COMPOSER_HOME=/home/orbit/.config/composer"]),
    ]);
    // The Gateway-owned step expands to the shared CI cache program, never a literal command.
    assertEquals(setupArgv(setup("@orbit/ci-caches"), "/tmp/run"), [
        argv("/tmp/run/output", "setup-0", ciCacheImportProgram, ["COMPOSER_HOME=/home/orbit/.config/composer"]),
    ]);
    assertEquals(
        teardownArgv({
            checkout_path: "/home/orbit/apps/project/workspace",
            ssh_execution: { target: { user: "orbit", host: "10.44.0.20" } },
            teardown: [{
                input_json: JSON.stringify({
                    name: "drop",
                    command: "rm -f db.sqlite",
                    timeout_seconds: 30,
                }),
                timeout_seconds: 30,
            }],
        }, "/tmp/teardown"),
        { teardown: [argv("/tmp/teardown", "teardown-0", "rm -f db.sqlite")] },
    );
});

Deno.test("the checkout reads become the identity and source profile", () => {
    const workspace = Intent.parse({
        ...intent,
        workspace: {
            ...intent.workspace,
            project_type: "laravel-app",
            skip_profile: false,
        },
    }).workspace;
    const head = "c".repeat(40);
    const composer = '{"require":{"php":"^8.4","laravel/framework":"^13"}}';
    const read = (
        files: string,
        base = "b".repeat(40) + "\n",
        bytes: Uint8Array | null = new TextEncoder().encode(composer),
    ) => ({
        identity: head + "\ntask-30\n",
        base,
        files,
        composer: bytes,
    });
    const laravel = observe(
        workspace,
        read("composer.json|regular file|52\nartisan|regular file|300\n"),
    );
    assertEquals(laravel.branch, "task-30");
    assertEquals(laravel.commit, head);
    assertEquals(laravel.profile, {
        kind: "composer",
        composer: btoa(composer),
        entry_point: "regular",
    });
    // inspect saw a regular composer.json, so a missing download is a failure.
    assertThrows(
        () =>
            observe(
                workspace,
                read("composer.json|regular file|52\n", undefined, null),
            ),
        Error,
        "tasks.provision_failed",
    );
    // Git found no merge base: the pin is unavailable, never the current HEAD.
    assertEquals(observe(workspace, read("")).profile.kind, "none");
    assertEquals(
        observe(workspace, read("artisan|regular file|300\n")).profile.kind,
        "partial",
    );
    assertEquals(
        observe(workspace, read("composer.json|symbolic link|13\n")).profile
            .kind,
        "unsafe",
    );
    assertEquals(
        observe(workspace, read("composer.json|regular file|1048577\n")).profile
            .kind,
        "unsafe",
    );
    assertEquals(
        observe(
            workspace,
            read("composer.json|regular file|52\nartisan|symbolic link|7\n"),
        ).profile.entry_point,
        "unsafe",
    );
    assertEquals(
        observe(workspace, read("composer.json|regular file|52\n")).profile
            .entry_point,
        "absent",
    );
    assertEquals(
        observe(
            { ...workspace, skip_profile: true },
            read("composer.json|regular file|52\n"),
        ).profile.kind,
        "none",
    );
});

Deno.test("bind pins only the unpinned guest targets to the key the VM step observed", () => {
    const value: Record<string, unknown> = {
        route: {
            nodes: {
                "20": { host_keys: null },
                "11": { host_keys: ["ssh-ed25519 ROUTER"] },
            },
            list: [{ host_keys: null }],
        },
        processes: [{
            id: 70,
            intent: {
                ssh_execution: {
                    target: { host: "10.44.0.20", host_keys: null },
                },
            },
        }],
    };
    assertEquals(bindHostKeys(value, vm, 20), {
        route: {
            nodes: {
                "20": { host_keys: ["ssh-ed25519 PUBLIC"] },
                "11": { host_keys: ["ssh-ed25519 ROUTER"] },
            },
            list: [{ host_keys: ["ssh-ed25519 PUBLIC"] }],
        },
        processes: [{
            id: 70,
            intent: {
                ssh_execution: {
                    target: {
                        host: "10.44.0.20",
                        host_keys: ["ssh-ed25519 PUBLIC"],
                    },
                },
            },
        }],
    });
    assertThrows(
        () => bindHostKeys(value, vm, 21),
        Error,
        "tasks.provision_refused",
    );
});

Deno.test("pin takes the provision receipt as an object, as a provision run holds it", () => {
    const key = ["ssh-ed25519 PUBLIC"];
    const intents = { converge: { ssh: { target: { host: "10.44.0.20", host_keys: null } } } };
    const pinned = pinIntents(vm, 20, intents);
    assertEquals(pinned.converge.ssh.target.host_keys, key);
});

Deno.test("pin fills the host key of every guest target of a prep run from the printed receipt", () => {
    const target = (key: unknown) => ({
        ssh_execution: { target: { host: "10.44.0.20", host_keys: key } },
    });
    const intents = {
        draft: target(null),
        fix: target(null),
        reviews: [target(null), target(null), target(null)],
        publish: target(null),
    };
    const pinned = pinIntents(JSON.stringify(vm) + "\n", 20, intents);
    const key = ["ssh-ed25519 PUBLIC"];
    assertEquals(pinned.draft, target(key));
    assertEquals(pinned.fix, target(key));
    assertEquals(pinned.reviews, [target(key), target(key), target(key)]);
    assertEquals(pinned.publish, target(key));
    assertThrows(
        () => pinIntents(JSON.stringify(vm), 21, intents),
        Error,
        "tasks.provision_refused",
    );
    assertThrows(() =>
        pinIntents(JSON.stringify({ ...vm, ready: false }), 20, intents)
    );
});

Deno.test("the revoke outcome tells a revoked token from one GitHub does not know and from one left unrevoked", () => {
    const revoke = (status: string, stdout?: string) =>
        revokeOutcome({ Revoke: { status, outputs: stdout === undefined ? {} : { stdout } } });

    assertEquals(revoke("succeeded", "204"), { state: "revoked" });
    assertEquals(revoke("succeeded", "401\n").state, "none");
    for (const code of ["403", "404", "500", "503"]) {
        const outcome = revoke("succeeded", code);
        assertEquals(outcome.state, "unrevoked");
        assertEquals(outcome.reason, `GitHub answered HTTP ${code} to the revoke.`);
    }
    // curl printed something that is not a status: the reason never repeats it.
    const odd = revoke("succeeded", "ghs_NotAStatus");
    assertEquals(odd.state, "unrevoked");
    assertEquals(odd.reason, "GitHub answered HTTP an unknown status to the revoke.");
    // The request itself failed, so a token may still be valid.
    assertEquals(revoke("failed"), {
        state: "unrevoked",
        reason: "The revoke request did not complete.",
    });
    // The step failed before its command ran: the vault key could not be read.
    assertEquals(
        revokeOutcome({
            Revoke: {
                status: "failed",
                error: "Failed to resolve vault expression vault.get(...): Secret 'x' not found in vault 'orbit-control-inputs'",
            },
        }),
        { state: "unrevoked", reason: "The revoke step could not run." },
    );
    // No revoke step ran: nothing says the token is gone.
    assertEquals(revokeOutcome({}), { state: "unrevoked", reason: "The revoke step could not run." });
});
Deno.test("the receipt names what became of the setup token, and a missing report is never a success", () => {
    const reported = { state: "revoked" };
    assertEquals(
        dependencyToken({ deps_write: { status: "succeeded" }, deps_remove: { status: "succeeded", outputs: { result: reported } } }),
        reported,
    );
    assertEquals(
        dependencyToken({
            deps_write: { status: "succeeded" },
            deps_remove: { status: "succeeded", outputs: { result: { state: "unrevoked", reason: "GitHub answered HTTP 503 to the revoke." } } },
        }),
        { state: "unrevoked", reason: "GitHub answered HTTP 503 to the revoke." },
    );
    // The write step ran and nothing was reported back.
    assertEquals(dependencyToken({ deps_write: { status: "succeeded" }, deps_remove: { status: "succeeded", outputs: {} } }).state, "unrevoked");
    assertEquals(
        dependencyToken({ deps_write: { status: "succeeded" }, deps_remove: { status: "succeeded", outputs: { result: { state: "fine" } } } }).state,
        "unrevoked",
    );
    // The write step was skipped: nothing was written, so nothing needed revoking.
    assertEquals(dependencyToken({}), { state: "none", reason: "No setup step ran." });

    const source = { branch: "task-30", commit: "a".repeat(40), php_version: null, laravel: false };
    const steps: Record<string, { status: string; outputs?: unknown }> = Object.fromEntries(
        ["prepare", "directory", "control", "mint_clone", "clone", "bundle", "prepare_remote", "copy_bundle", "import", "origin", "branch", "identity", "inspect", "composer", "profile", "deps_write"]
            .map((name) => [name, { status: "succeeded", outputs: {} }]),
    );
    steps.guest = { status: "succeeded" };
    steps.profile.outputs = { source };
    steps.deps_remove = { status: "succeeded", outputs: { result: { state: "none", reason: "GitHub holds no valid setup token." } } };

    const receipt = result(intent, vm, steps);
    assertEquals(receipt.dependency_token, { state: "none", reason: "GitHub holds no valid setup token." });
    assertEquals(receipt.source, source);
});
