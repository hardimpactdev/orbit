import { assert, assertEquals } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.12";

// deno-lint-ignore no-explicit-any
type Task = Record<string, any>;
const tasks = parse(Deno.readTextFileSync(
    new URL("../ansible/tasks/role-swamp.yml", import.meta.url),
)) as Task[];
const named = (name: string) => tasks.find((task) => task.name === name)!;

Deno.test("the Swamp Ansible phase opens worker credentials before writing or starting units", () => {
    const credentials = named(
        "Give workers access to their private run folders",
    );
    const unit = named("Write Swamp service units");
    assertEquals(credentials.loop, "{{ rt.swamp.workers }}");
    assertEquals(
        credentials["ansible.builtin.file"].group,
        "{{ rt.swamp.user }}",
    );
    assertEquals(credentials["ansible.builtin.file"].recurse, true);
    assert(tasks.indexOf(credentials) < tasks.indexOf(unit));
    assert(
        tasks.indexOf(unit) < tasks.indexOf(named("Start the Swamp workers")),
    );
    assert(
        tasks.indexOf(named("Start the Swamp workers")) <
            tasks.indexOf(named("Start worker renewal after both workers")),
    );
});

Deno.test("the credentials end as folders 0750 and token files 0640", () => {
    const root = Deno.makeTempDirSync();
    try {
        Deno.mkdirSync(`${root}/a`);
        Deno.writeTextFileSync(`${root}/a/enrollment.token`, "x");
        Deno.writeTextFileSync(`${root}/a/server.token`, "x");
        Deno.chmodSync(`${root}/a`, 0o700);
        Deno.chmodSync(`${root}/a/enrollment.token`, 0o600);
        Deno.chmodSync(`${root}/a/server.token`, 0o600);
        const mode = named(
            "Give workers access to their private run folders",
        )["ansible.builtin.file"].mode;
        const chmod = new Deno.Command("/bin/chmod", {
            args: ["-R", mode, `${root}/a`],
        }).outputSync();
        assert(chmod.success);
        assertEquals(Deno.statSync(`${root}/a`).mode! & 0o777, 0o750);
        assertEquals(
            Deno.statSync(`${root}/a/enrollment.token`).mode! & 0o777,
            0o640,
        );
        assertEquals(
            Deno.statSync(`${root}/a/server.token`).mode! & 0o777,
            0o640,
        );
    } finally {
        Deno.removeSync(root, { recursive: true });
    }
});
