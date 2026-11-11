import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.9";

// The real "Check work is pushed" shell program from the destroy workflow, run against a scratch checkout.
const workflow = parse(
    await Deno.readTextFile(
        new URL(
            "../../../../workflows/workflow-operation-instance-destroy.yaml",
            import.meta.url,
        ),
    ),
) as {
    jobs: { steps: { name: string; task: { inputs: { argv: string; run?: string; env?: Record<string, string> } } }[] }[];
};
const argv =
    workflow.jobs.flatMap((job) => job.steps).find((step) =>
        step.name === "Check work is pushed"
    )!.task.inputs.argv;
const script = JSON.parse(
    argv.match(/"\/bin\/sh", "-c", ("(?:[^"\\]|\\.)*")/)![1],
).replaceAll("/usr/bin/git", "git");

const git = (dir: string, ...args: string[]) =>
    new Deno.Command("git", {
        args: ["-C", dir, ...args],
        env: { GIT_CONFIG_NOSYSTEM: "1" },
    }).outputSync();
async function checkout(): Promise<string> {
    const root = await Deno.makeTempDir();
    git(root, "init", "-q", "--bare", "origin.git");
    git(root, "clone", "-q", `${root}/origin.git`, "work");
    const work = `${root}/work`;
    git(
        work,
        "-c",
        "user.email=f@example.test",
        "-c",
        "user.name=F",
        "commit",
        "-q",
        "--allow-empty",
        "-m",
        "fixture",
    );
    git(work, "push", "-q", "origin", "HEAD");
    return work;
}
const pushed = (work: string) =>
    new Deno.Command("/bin/sh", {
        args: ["-c", script, "sh", "/dev/null", work],
        stderr: "null",
    }).outputSync().code === 0;

Deno.test("the files Orbit renders do not count as unpushed work, at any depth", async () => {
    const work = await checkout();
    await Deno.mkdir(`${work}/apps/site`, { recursive: true });
    for (
        const file of [
            ".env",
            ".env.testing",
            "apps/site/.env",
            "apps/site/.env.testing",
        ]
    ) {
        await Deno.writeTextFile(`${work}/${file}`, "APP_ENV=testing\n");
    }
    assertEquals(pushed(work), true);
});

Deno.test("any other change is still work that is not pushed", async () => {
    for (const file of ["app.php", "foo.env.testing"]) {
        const work = await checkout();
        await Deno.writeTextFile(`${work}/${file}`, "x\n");
        assertEquals(pushed(work), false, file);
    }
});

Deno.test("every destroy Ansible step enables failed task stderr", () => {
    const steps = workflow.jobs.flatMap((job) => job.steps).filter((step) =>
        step.task.inputs.run?.includes("ansible-playbook")
    );
    assertEquals(steps.length, 1);
    for (const step of steps) {
        assertEquals(step.task.inputs.env?.ANSIBLE_DISPLAY_FAILED_STDERR, "1", step.name);
    }
});
