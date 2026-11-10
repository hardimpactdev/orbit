import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

const source = await Deno.readTextFile(
    new URL(
        "../workflows/workflow-operation-gateway-swamp-load.yaml",
        import.meta.url,
    ),
);
const workflow = parse(source) as {
    jobs: { steps: { name: string; task: { inputs: { run: string } } }[] }[];
};
const run =
    workflow.jobs[0].steps.find((step) => step.name === "load")!.task.inputs
        .run;

Deno.test("the release load compares only the commit's workflows, never a Schedule's", async () => {
    const filter = /^\s*tracked='([^']+)'$/m.exec(run)?.[1];
    assertEquals(typeof filter, "string");
    const listed = {
        results: [
            { id: "b", name: "operation-route-create" },
            { id: "s", name: "schedule-077c43f7-dd1c-4576-af12-b269e83cddfe" },
            { id: "a", name: "operation-schedule-create" },
        ],
    };
    const jq = new Deno.Command("jq", {
        args: ["-ec", filter!],
        stdin: "piped",
        stdout: "piped",
    }).spawn();
    const writer = jq.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(listed)));
    await writer.close();
    const output = await jq.output();
    assertEquals(output.success, true);
    assertEquals(JSON.parse(new TextDecoder().decode(output.stdout)), [
        ["a", "operation-schedule-create"],
        ["b", "operation-route-create"],
    ]);
    assertEquals(run.includes('jq -ec "$tracked"'), true);
});

type Step = {
    name: string;
    allowFailure?: boolean;
    task: { inputs: { run: string; env?: Record<string, string> } };
};
const check = ((workflow.jobs[0].steps as unknown) as Step[]).find((step) =>
    step.name === "Check the Ansible collections"
)!;

/** Runs the collections check against a requirements.yml and a pin of its own. */
async function checkCollections(pin: string) {
    const folder = await Deno.makeTempDir();
    await Deno.writeTextFile(
        `${folder}/requirements.yml`,
        "collections:\n  - {name: ansible.posix, version: 2.2.2}\n",
    );
    await Deno.writeTextFile(`${folder}/pin.yml`, pin);
    const done = await new Deno.Command("sh", {
        args: ["-c", check.task.inputs.run],
        env: {
            REQUIREMENTS: `${folder}/requirements.yml`,
            PIN: `${folder}/pin.yml`,
            NODE_ID: "209",
        },
        stdout: "piped",
        stderr: "piped",
    }).output();
    await Deno.remove(folder, { recursive: true });
    return {
        code: done.code,
        stdout: new TextDecoder().decode(done.stdout).trim(),
        stderr: new TextDecoder().decode(done.stderr).trim(),
    };
}

Deno.test("the release load checks the Ansible collections without failing the release, and installs nothing", () => {
    assertEquals(check.allowFailure, true);
    assertEquals(
        check.task.inputs.env?.PIN,
        "/opt/orbit/ansible/requirements.yml",
    );
    assertEquals(
        /ansible-galaxy|cp |install /.test(check.task.inputs.run),
        false,
    );
});

Deno.test("a pin equal to the release's requirements.yml passes", async () => {
    const run = await checkCollections(
        "collections:\n  - {name: ansible.posix, version: 2.2.2}\n",
    );
    assertEquals([run.code, run.stdout], [0, "current"]);
});

Deno.test("a pin from another requirements.yml fails the check with the converge that installs the collections", async () => {
    const run = await checkCollections(
        "collections:\n  - {name: community.general, version: 12.0.1}\n",
    );
    assertEquals(run.code, 1);
    assertEquals(
        run.stderr.endsWith("run: orbit node:role:add 209 swamp --converge"),
        true,
    );
});

type Checkout = {
    name: string;
    task: { inputs: { run: string } };
};
const checkout = ((workflow.jobs[0].steps as unknown) as Checkout[]).find((
    step,
) => step.name === "checkout")!;

/** Runs a Git command in a folder and returns its trimmed stdout. */
async function git(folder: string, ...args: string[]) {
    const done = await new Deno.Command("git", {
        args: ["-C", folder, ...args],
        env: {
            GIT_AUTHOR_NAME: "t",
            GIT_AUTHOR_EMAIL: "t@t",
            GIT_COMMITTER_NAME: "t",
            GIT_COMMITTER_EMAIL: "t@t",
        },
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(done.success, true, new TextDecoder().decode(done.stderr));
    return new TextDecoder().decode(done.stdout).trim();
}

Deno.test("a rollback release moves the serve checkout back to an ancestor commit", async () => {
    const folder = await Deno.makeTempDir();
    const origin = `${folder}/origin`;
    await Deno.mkdir(origin);
    await git(origin, "init", "--quiet");
    await git(origin, "commit", "--quiet", "--allow-empty", "-m", "older");
    const older = await git(origin, "rev-parse", "HEAD");
    await git(origin, "commit", "--quiet", "--allow-empty", "-m", "newer");
    const newer = await git(origin, "rev-parse", "HEAD");
    await git(folder, "clone", "--quiet", origin, "checkout");
    const target = `${folder}/checkout`;
    const run = checkout.task.inputs.run.replace(
        "/home/orbit/apps/orbit-monorepo-staging/checkout",
        target,
    );
    const done = await new Deno.Command("sh", {
        args: ["-c", run],
        env: { RELEASE_SHA: older, PATH: Deno.env.get("PATH")! },
        stdout: "piped",
        stderr: "piped",
    }).output();
    const head = await git(target, "rev-parse", "HEAD");
    await Deno.remove(folder, { recursive: true });
    assertEquals(done.code, 0, new TextDecoder().decode(done.stderr));
    assertEquals(new TextDecoder().decode(done.stdout).trim(), newer);
    assertEquals(head, older);
});
