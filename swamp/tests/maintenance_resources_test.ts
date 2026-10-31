import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Context,
  failureReceipts,
} from "../extensions/behaviors/_lib/maintenance/context.ts";
import { MaintenanceFailure } from "../extensions/behaviors/_lib/maintenance/io.ts";
import { behavior } from "../extensions/behaviors/js-maintenance/mod.ts";

Deno.test("failure capture is opt-in and never swallows abort or infrastructure failure", async () => {
  const receipts: Record<string, unknown>[] = [];
  const ctx: Context = {
    writeResource: (_spec, name, data) => {
      receipts.push(data);
      return Promise.resolve({ name });
    },
  };
  let error: Error = new MaintenanceFailure("update failed", 23);
  const methods = failureReceipts({
    run: {
      execute: (_input: { captureFailure?: boolean }, _ctx: Context) =>
        Promise.reject(error),
    },
  });
  await assertRejects(() => methods.run.execute({}, ctx), MaintenanceFailure);
  await methods.run.execute({ captureFailure: true }, ctx);
  assertEquals(receipts, [{ exitCode: 23, message: "update failed" }]);
  const controller = new AbortController();
  controller.abort();
  await assertRejects(
    () =>
      methods.run.execute({ captureFailure: true }, {
        ...ctx,
        signal: controller.signal,
      }),
    MaintenanceFailure,
  );
  error = new Error("worker transport lost");
  await assertRejects(
    () => methods.run.execute({ captureFailure: true }, ctx),
    Error,
    "transport lost",
  );
  assertEquals(receipts.length, 1);
});

Deno.test("snapshot includes staged, unstaged and untracked files without changing HEAD or the real index", async () => {
  const cwd = await Deno.makeTempDir({ prefix: "maintenance-snapshot-" });
  const git = async (...args: string[]) => {
    const out = await new Deno.Command("git", {
      cwd,
      args,
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(out.success, true, new TextDecoder().decode(out.stderr));
    return new TextDecoder().decode(out.stdout).trim();
  };
  try {
    await git("init");
    await Deno.writeTextFile(`${cwd}/tracked`, "base\n");
    await git("add", ".");
    await git(
      "-c",
      "user.name=Fixture",
      "-c",
      "user.email=fixture@example.test",
      "commit",
      "-m",
      "base",
    );
    const head = await git("rev-parse", "HEAD");
    await Deno.writeTextFile(`${cwd}/tracked`, "staged\n");
    await git("add", ".");
    const index = await Deno.readFile(`${cwd}/.git/index`);
    await Deno.writeTextFile(`${cwd}/tracked`, "unstaged\n");
    await Deno.writeTextFile(`${cwd}/untracked`, "new\n");
    let snapshot: Record<string, unknown> = {};
    await behavior.methods.snapshot.execute({ cwd }, {
      writeResource: (spec, name, data) => {
        assertEquals(spec, "snapshot");
        snapshot = data;
        return Promise.resolve({ name });
      },
    });
    assertEquals(snapshot.head, head);
    assertEquals(await git("rev-parse", "HEAD"), head);
    assertEquals(await Deno.readFile(`${cwd}/.git/index`), index);
    assertEquals(await git("show", `${snapshot.tree}:tracked`), "unstaged");
    assertEquals(await git("show", `${snapshot.tree}:untracked`), "new");
  } finally {
    await Deno.remove(cwd, { recursive: true });
  }
});
