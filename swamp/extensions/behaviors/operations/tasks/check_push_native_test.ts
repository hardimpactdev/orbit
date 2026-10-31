import { assertEquals } from "jsr:@std/assert@1";
import { workspaceProgram } from "./script.ts";
import { CheckProgram } from "./check_program.ts";

// Replace only the fixed HTTPS endpoint; all Git/ref/config behavior stays native.
const transport = String.raw`
import json, os, pathlib, subprocess, sys
value = json.load(sys.stdin)
scope = {'__name__': 'orbit_push'}
exec(value['program'], scope)
original = subprocess.run
root, remote = value['input']['checkout_path'], value['remote']
def request(args, **kwargs):
    if 'https://github.com/acme/shop.git' in args:
        if root in args:
            raise RuntimeError('Authenticated Git must never use the repository config')
        if 'push' in args:
            with open(root + '/.git/pushes', 'a') as f: f.write('1\n')
        args = [remote if arg == 'https://github.com/acme/shop.git' else arg for arg in args]
        kwargs['env'] = {**kwargs['env'], 'GIT_ALLOW_PROTOCOL': 'file'}
    return original(args, **kwargs)
subprocess.run = request
print(json.dumps(scope['check'](value['input'])))
`;
async function fixture() {
    const root = await Deno.makeTempDir({ prefix: "task-publish-native-" });
    const work = root + "/work", remote = root + "/remote.git";
    await Deno.mkdir(work);
    const git = async (dir: string, ...args: string[]) => {
        const result = await new Deno.Command("git", {
            args: ["-C", dir, ...args],
            stdout: "piped",
            stderr: "null",
        }).output();
        if (!result.success) throw new Error("Fixture Git failed");
        return new TextDecoder().decode(result.stdout).trim();
    };
    await git(root, "init", "--bare", "-q", remote);
    await git(work, "init", "-qb", "task-1");
    await git(work, "config", "user.name", "Test");
    await git(work, "config", "user.email", "test@example.test");
    await Deno.writeTextFile(work + "/code", "one\n");
    await git(work, "add", ".");
    await git(work, "commit", "-qm", "one");
    const commit = await git(work, "rev-parse", "HEAD");
    const tree = await git(work, "rev-parse", "HEAD^{tree}");
    const input = {
        checkout_path: work,
        commit,
        tree,
        repository: "acme/shop",
        branch: "task-1",
        execution_key: "operation-15",
        mode: "push",
        token: "fixture-token-private",
    };
    return {
        root,
        work,
        remote,
        input,
        git,
        push: (value = input) =>
            workspaceProgram(transport, {
                program: CheckProgram,
                input: value,
                remote,
            }),
        dispose: () => Deno.remove(root, { recursive: true }),
    };
}
Deno.test("native push uses only the admitted SHA and skips an identical remote tip on recovery", async () => {
    const f = await fixture();
    try {
        assertEquals(await f.push(), { pushed: true });
        assertEquals(
            await f.git(f.remote, "rev-parse", "refs/heads/task-1"),
            f.input.commit,
        );
        assertEquals(await f.push(), { pushed: true });
        assertEquals(await Deno.readTextFile(f.work + "/.git/pushes"), "1\n");
        const config = await Deno.readTextFile(f.work + "/.git/config");
        assertEquals(config.includes(f.input.token), false);
    } finally {
        await f.dispose();
    }
});
Deno.test("a divergent task branch is never forced", async () => {
    const f = await fixture();
    try {
        await f.push();
        await f.git(f.work, "checkout", "--orphan", "unrelated");
        await f.git(f.work, "commit", "--allow-empty", "-qm", "other root");
        const other = await f.git(f.work, "rev-parse", "HEAD");
        assertEquals(await f.push({ ...f.input, commit: other }), {
            error_code: "tasks.not_fast_forward",
        });
        assertEquals(
            await f.git(f.remote, "rev-parse", "refs/heads/task-1"),
            f.input.commit,
        );
    } finally {
        await f.dispose();
    }
});
Deno.test("repository hooks and Git config cannot run with the credential", async () => {
    const f = await fixture();
    try {
        await Deno.mkdir(f.root + "/hooks");
        const marker = f.root + "/untrusted-ran";
        await Deno.writeTextFile(
            f.root + "/hooks/pre-push",
            `#!/bin/sh\nprintf leaked > '${marker}'\nexit 1\n`,
            { mode: 0o755 },
        );
        await f.git(f.work, "config", "core.hooksPath", f.root + "/hooks");
        await f.git(
            f.work,
            "config",
            "url.ext::false.insteadOf",
            "https://github.com/",
        );
        await f.git(f.work, "config", "credential.helper", "!false");
        assertEquals(await f.push(), { pushed: true });
        assertEquals(
            await Deno.stat(marker).then(() => true, () => false),
            false,
        );
    } finally {
        await f.dispose();
    }
});
Deno.test("an uncommitted tree change prevents the authenticated push", async () => {
    const f = await fixture();
    try {
        await Deno.writeTextFile(f.work + "/code", "changed\n");
        assertEquals(await f.push(), { error_code: "tasks.head_moved" });
        assertEquals(
            await Deno.stat(f.work + "/.git/pushes").then(
                () => true,
                () => false,
            ),
            false,
        );
    } finally {
        await f.dispose();
    }
});

Deno.test("a GitHub workflow-permission refusal retains its typed code", async () => {
    const f = await fixture();
    try {
        await Deno.mkdir(f.remote + "/hooks", { recursive: true });
        await Deno.writeTextFile(
            f.remote + "/hooks/pre-receive",
            "#!/bin/sh\necho 'refusing to allow a GitHub App to create or update workflow .github/workflows/ci.yml' >&2\nexit 1\n",
            { mode: 0o755 },
        );
        assertEquals(await f.push(), {
            error_code: "tasks.workflows_permission",
        });
    } finally {
        await f.dispose();
    }
});
