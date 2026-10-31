import { z } from "npm:zod@4.4.3";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

/** Only the two retired, code-owned source locations. Never an admitted arbitrary path. */
export const sourceRemovalProgram = String.raw`
import json,pathlib,shutil,stat,sys
ROOT=pathlib.Path('/var/lib/orbit')
class Refused(Exception):pass
def directory(path):
 try:s=path.lstat()
 except FileNotFoundError:return False
 if not stat.S_ISDIR(s.st_mode):raise Refused()
 return True
def remove(value):
 if type(value) is not dict or set(value)!={'retired'} or type(value['retired']) is not bool:raise Refused()
 for parent in [ROOT,*ROOT.parents]:directory(parent)
 target=ROOT/('proxycli' if value['retired'] else 'ai-router')
 if not directory(target):return {'removed':False}
 shutil.rmtree(target)
 return {'removed':True}
if __name__=='__main__':
 try:print(json.dumps(remove(json.load(sys.stdin))))
 except Refused:print(json.dumps({'error_code':'ai-router.source_unsafe'}))
 except Exception:print(json.dumps({'error_code':'ai-router.source_remove_failed'}))
`;
const Args = z.object({ retired: z.boolean() }).strict();
const Result = z.object({ removed: z.boolean() }).strict();
export function removeAiRouterSource(
  transport: SystemTransport = localSystemCommand,
) {
  return async (
    raw: z.infer<typeof Args>,
    signal?: AbortSignal,
  ): Promise<z.infer<typeof Result>> => {
    const input = Args.parse(raw);
    let reply;
    try {
      reply = await transport({
        command: "/usr/bin/sudo",
        args: [
          "-n",
          "--",
          "/usr/bin/python3",
          "-I",
          "-c",
          sourceRemovalProgram,
        ],
        stdin: JSON.stringify(input),
        timeoutMs: 30000,
        maxOutputBytes: 4096,
      }, signal);
    } catch {
      throw new Error("ai-router.source_outcome_unknown");
    }
    if (!reply.success) throw new Error("ai-router.source_outcome_unknown");
    let value;
    try {
      value = JSON.parse(reply.stdout);
    } catch {
      throw new Error("ai-router.source_outcome_unknown");
    }
    if (
      ["ai-router.source_unsafe", "ai-router.source_remove_failed"].includes(
        value?.error_code,
      )
    ) throw new Error(value.error_code);
    const result = Result.safeParse(value);
    if (!result.success) throw new Error("ai-router.source_outcome_unknown");
    return result.data;
  };
}
/** Parent lifecycle uses this same placed step; it never starts a child workflow or Operation. */
export function aiRouterSource(remove = removeAiRouterSource()) {
  return {
    type: "@nckrtl/ai-router-source",
    version: "2026.10.31.1",
    methods: {
      remove: {
        description:
          "Remove only the selected code-owned retired collector source directory",
        arguments: Args,
        execute: (args: z.infer<typeof Args>, ctx: { signal?: AbortSignal }) =>
          remove(args, ctx.signal),
      },
    },
  };
}
export const behavior = aiRouterSource();
