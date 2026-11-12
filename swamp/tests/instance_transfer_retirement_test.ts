/** Real Git and the shipped retirement phase; two unprivileged Ansible launches. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import {workflow, retirement, archive, text, quote, run, git, fixture} from "./_support/transfer_retirement.ts";
for (const late of [true, false]) {
    Deno.test(`transfer retirement ${late ? "refuses a recorded worktree moved after archive" : "removes an independent source"}`, () => {
        const f = fixture();
        try {
            const packed = run(archive.task.inputs.argv[0], f.archiveArgs.slice(1));
            assert(packed.success, text(packed.stderr));
            if (late) {
                git(f.source, "worktree", "add", "-q", "--detach", f.linked);
                Deno.renameSync(f.linked, f.moved);
            }
            const original = git(f.source, "rev-parse", "HEAD");
            const done = run("sh", ["-c", f.command], f.env);
            assertEquals(done.success, !late, text(done.stdout) + text(done.stderr));
            const receipt = JSON.parse(text(done.stdout));
            assertEquals(receipt, JSON.parse(Deno.readTextFileSync(f.state + "/source-result.json")));
            assertEquals(receipt.outcome, late ? "refused" : "removed");
            if (late) {
                assertEquals(receipt.data.error_code, "instance.worktree_dependents");
                assertEquals(receipt.data.dependents, [{path: f.moved, instance_ids: [23]}]);
                assertEquals(git(f.moved, "rev-parse", "HEAD"), original);
                assertEquals(Deno.readTextFileSync(f.source + "/work"), "preserve owner work");
            } else assert(!Array.from(Deno.readDirSync(f.folder)).some((entry) => entry.name === "owner"));
        } finally { Deno.removeSync(f.folder, {recursive: true}); }
    });
}

Deno.test("transfer without the new inventory key refuses safely before any source removal", () => {
    const f = fixture();
    try {
        const done = run("sh", ["-c", f.command], {...f.env,ORBIT_CHECKOUT_INVENTORY:"0"});
        assert(!done.success);
        assertEquals(JSON.parse(text(done.stdout)), {outcome:"refused",data:{error_code:"instance.remove_refused",reason:"checkout_inventory_missing"}});
        assertEquals(Deno.readTextFileSync(f.source + "/work"), "preserve owner work");
    } finally { Deno.removeSync(f.folder,{recursive:true}); }
});
