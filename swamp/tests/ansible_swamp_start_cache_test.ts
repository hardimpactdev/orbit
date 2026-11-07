/** The Swamp start cache stays readable by the swamp account, and no Ansible task runs Swamp as root to rewrite it. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

const ansible = new URL("../ansible/", import.meta.url);
// deno-lint-ignore no-explicit-any
type Item = Record<string, any>;
// The playbook writes `!!null ""` for Ansible's argument-free modules; the strict YAML parser refuses that tag.
const load = (path: string): Item[] => {
    const document = parse(
        Deno.readTextFileSync(new URL(path, ansible)).replaceAll(
            '!!null ""',
            "null",
        ),
    );
    return Array.isArray(document) ? document : [];
};

Deno.test("the node phase gives an existing start cache to the swamp account, and the check phase asserts it", () => {
    const byName = Object.fromEntries(
        load("tasks/swamp-start-cache.yml").map((task) => [task.name, task]),
    );
    const repair = byName["Give the Swamp start cache to the swamp account"];
    assertEquals(repair["ansible.builtin.file"], {
        path: "{{ swamp_start_cache }}",
        state: "file",
        owner: "{{ rt.swamp.user }}",
        mode: "0644",
    });
    assertEquals(
        repair.when,
        "not swamp_cache_check_only and swamp_start_cache_file.stat.exists",
    );
    const check = byName["Check the Swamp start cache"];
    assertEquals(check.when.startsWith("swamp_cache_check_only"), true);
    assertEquals(check["ansible.builtin.assert"].that, [
        "swamp_start_cache_file.stat.pw_name == rt.swamp.user",
        "swamp_start_cache_file.stat.rusr",
    ]);
    const imports = load("operation-node-role-add.yml").flatMap((play) =>
        (play.tasks ?? []).filter((task: Item) =>
            task["ansible.builtin.import_tasks"] ===
                "tasks/swamp-start-cache.yml"
        ).map((task: Item) => [play.tags[0], task.vars.swamp_cache_check_only])
    );
    assertEquals(imports, [["node", false], ["check", true]]);
});

/** Every task of every play, including handlers and blocks, with the play it belongs to. */
function* tasksOf(items: Item[], play: Item = {}): Generator<[Item, Item]> {
    for (const item of items) {
        const isPlay = "hosts" in item;
        const context = isPlay ? item : play;
        if (isPlay) {
            yield* tasksOf(
                [
                    ...(item.pre_tasks ?? []),
                    ...(item.tasks ?? []),
                    ...(item.handlers ?? []),
                ],
                item,
            );
            continue;
        }
        yield [item, context];
        yield* tasksOf(
            [
                ...(item.block ?? []),
                ...(item.rescue ?? []),
                ...(item.always ?? []),
            ],
            context,
        );
    }
}

const swampCall = /(^|[\s;&|(])(\/usr\/local\/bin\/)?swamp(\s|$)/m;

const commandModules = ["command", "shell", "raw", "script"].flatMap((
    name,
) => [name, `ansible.builtin.${name}`]);

function commandText(task: Item): string {
    const module = commandModules.find((name) => name in task);
    const body = module === undefined ? undefined : task[module];
    if (typeof body === "string") return body;
    if (body?.cmd) return String(body.cmd);
    if (Array.isArray(body?.argv)) return body.argv.join(" ");
    return "";
}

Deno.test("no Ansible task runs the swamp binary without naming a user other than root", () => {
    const offenders: string[] = [];
    const files = [...Deno.readDirSync(ansible)].filter((f) =>
        f.name.endsWith(".yml")
    )
        .map((f) => f.name)
        .concat(
            [...Deno.readDirSync(new URL("tasks/", ansible))].filter((f) =>
                f.name.endsWith(".yml")
            )
                .map((f) => `tasks/${f.name}`),
        );
    for (const file of files) {
        // A tasks file runs under the play that imports it, and every play that does escalates (`become: true`).
        const inherited = file.startsWith("tasks/") ? { become: true } : {};
        for (const [task, play] of tasksOf(load(file), inherited)) {
            if (!swampCall.test(commandText(task))) continue;
            const become = task.become ?? play.become ?? false;
            const user = task.become_user ?? play.become_user;
            if (become !== false && (user === undefined || user === "root")) {
                offenders.push(`${file}: ${task.name}`);
            }
        }
    }
    // A root run under a restrictive umask rewrote the start cache as root with mode 0600 (2026-11-06 19:16 UTC).
    assertEquals(offenders, []);
    assert(files.length > 10);
});
