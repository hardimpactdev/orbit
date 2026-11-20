// The copy turn's receipt phases remove a link itself and never follow it (docs/reference/tasks.md#the-delivery-receipt). Both shell
// scripts run as the playbook holds them, against a repository of their own whose `orbit-handoff` folder or receipt is a link.
import { assert, assertEquals } from "jsr:@std/assert@1";

const playbook = Deno.readTextFileSync(new URL("../ansible/task-copy-turn.yml", import.meta.url));

/** The script of the task named NAME: its single-quoted shell text, which holds no single quote, as the folded YAML keeps it. */
function script(name: string): string {
    const task = playbook.slice(playbook.indexOf(`- name: ${name}`));
    const start = task.indexOf("'g=$(git -C /srv/workspace rev-parse --absolute-git-dir)");
    const end = task.indexOf("'", start + 1);
    assert(start >= 0 && end > start, name);
    return task.slice(start + 1, end);
}

/** Runs SCRIPT for the repository at ROOT, with ARGS after `sh`. */
function run(source: string, root: string, args: string[]) {
    const out = new Deno.Command("sh", { args: ["-c", source.replaceAll("/srv/workspace", root), "sh", ...args] }).outputSync();
    return { code: out.code, stdout: new TextDecoder().decode(out.stdout) };
}

const gone = (path: string) => {
    try {
        Deno.lstatSync(path);
        return false;
    } catch {
        return true;
    }
};
const exists = (path: string) => !gone(path);

Deno.test("the receipt phases remove a linked receipt folder or receipt itself and leave its target untouched", () => {
    const clear = script("Clear earlier handoff receipts");
    const read = script("Read and remove the handoff receipts");
    const root = Deno.makeTempDirSync();
    const target = Deno.makeTempDirSync();
    try {
        assert(new Deno.Command("git", { args: ["init", "-q", root] }).outputSync().success);
        const folder = `${root}/.git/orbit-handoff`;
        Deno.writeTextFileSync(`${target}/delivery.json`, "{\"commit\": \"target\"}");
        for (const [source, args, code] of [[clear, [], 0], [read, ["delivery"], 3]] as const) {
            // A linked receipt folder: the link goes, its target's receipt stays and is never read.
            Deno.symlinkSync(target, folder);
            const linked = run(source, root, [...args]);
            assertEquals([linked.code, linked.stdout], [code, ""]);
            assert(gone(folder) && exists(`${target}/delivery.json`));
            assertEquals(Deno.readTextFileSync(`${target}/delivery.json`), "{\"commit\": \"target\"}");
            // A linked receipt in a real folder: the same.
            Deno.mkdirSync(folder);
            Deno.symlinkSync(`${target}/delivery.json`, `${folder}/delivery.json`);
            const receipt = run(source, root, [...args]);
            assertEquals([receipt.code, receipt.stdout], [code, ""]);
            assert(gone(`${folder}/delivery.json`) && exists(`${target}/delivery.json`));
            Deno.removeSync(folder, { recursive: true });
        }
        // A receipt that is there is read once and removed.
        Deno.mkdirSync(folder);
        Deno.writeTextFileSync(`${folder}/delivery.json`, "{}");
        assertEquals(run(read, root, ["delivery"]), { code: 0, stdout: "{}" });
        assert(gone(`${folder}/delivery.json`));
    } finally {
        Deno.removeSync(root, { recursive: true });
        Deno.removeSync(target, { recursive: true });
    }
});
