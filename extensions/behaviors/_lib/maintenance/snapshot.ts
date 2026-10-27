import { z } from "npm:zod@4";
import { command } from "./io.ts";
import { type Context } from "./context.ts";

export const SnapshotSchema = z.strictObject({
  head: z.string(),
  tree: z.string(),
});
export const snapshotMethod = {
  description:
    "Snapshot the maintenance checkout with a temporary Git index; do not commit",
  arguments: z.strictObject({ cwd: z.string().min(1) }),
  execute: async (args: { cwd: string }, ctx: Context) => {
    const index = await Deno.makeTempFile({ prefix: "maintenance-index-" });
    try {
      const git = async (...argv: string[]) => {
        const result = await command(args.cwd, "git", argv, ctx.signal, {
          GIT_INDEX_FILE: index,
        });
        if (result.code !== 0) {
          throw new Error("Cannot snapshot maintenance checkout");
        }
        return result.stdout.trim();
      };
      await git("read-tree", "HEAD");
      await git("add", "-A", "--", ".");
      const head = await git("rev-parse", "HEAD"),
        tree = await git("write-tree");
      const handle = await ctx.writeResource(
        "snapshot",
        "snapshot",
        { head, tree },
      );
      return { dataHandles: [handle] };
    } finally {
      await Deno.remove(index);
    }
  },
};
