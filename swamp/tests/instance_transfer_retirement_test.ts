/** Real Git and the shipped retirement phase; two unprivileged Ansible launches. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import {workflow, retirement, archive, text, quote, run, git, fixture} from "./_support/transfer_retirement.ts";
for (const late of [true, false]) {
    Deno.test(`transfer retirement ${late ? "refuses a real worktree added after archive" : "removes an independent source"}`, () => {
        const f = fixture();
        try {
            const packed = run(archive.task.inputs.argv[0], f.archiveArgs.slice(1));
            assert(packed.success, text(packed.stderr));
            if (late) git(f.source, "worktree", "add", "-q", "--detach", f.linked);
            const original = git(f.source, "rev-parse", "HEAD");
            const done = run("sh", ["-c", f.command], f.env);
            assertEquals(done.success, !late, text(done.stdout) + text(done.stderr));
            const receipt = JSON.parse(Deno.readTextFileSync(f.state + "/source-result.json"));
            assertEquals(receipt.outcome, late ? "refused" : "removed");
            if (late) {
                assertEquals(receipt.data.error_code, "instance.worktree_dependents");
                assertEquals(receipt.data.dependents, [{path: f.linked, instance_ids: []}]);
                assertEquals(git(f.linked, "rev-parse", "HEAD"), original);
                assertEquals(Deno.readTextFileSync(f.source + "/work"), "preserve owner work");
            } else assert(!Array.from(Deno.readDirSync(f.folder)).some((entry) => entry.name === "owner"));
        } finally { Deno.removeSync(f.folder, {recursive: true}); }
    });
}
