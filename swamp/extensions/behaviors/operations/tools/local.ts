import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
import type { ToolIntentInput } from "./direct-contract.ts";
import { toolSystemProgram } from "./system_program.ts";
import { materializeProgram } from "./materialize_program.ts";

export const toolProgram = toolSystemProgram + materializeProgram + String.raw`
def dispatch(value):
 node=object_(value['node']);data=object_(value['input']);name=value['name'];manager=node['manager']
 if manager not in ('apt','composer','vp') or node['platform'] not in ('linux','macos') or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}',node['user']):refuse('tool.input_invalid')
 if node['platform']=='macos' and manager in ('apt','composer') or node['platform']=='linux' and manager=='brew-cask':refuse('tool.manager_unsupported')
 prefix='tool.'+manager+'-'
 materializer={'vp':'tool.materialize-vp','composer':'tool.materialize-composer'}.get(manager)
 if name==materializer:
  if node['platform']!='linux' or data.get('user')!=node['user']:refuse('tool.account_invalid')
  host=Host(node)
  return {'vp':vp_materialize,'composer':composer_materialize}[manager](host,data)
 special={'tool.inspect-vp-scope':('scope',manager=='vp')}
 if name in special:
  verb,allowed=special[name]
  if not allowed:refuse('tool.input_invalid')
 else:
  if not name.startswith(prefix):refuse('tool.input_invalid')
  verb=name[len(prefix):]
  if verb not in ('version','scope'):refuse('tool.input_invalid')
 allowed={'package','architecture','operation'} if verb not in ('version','inventory','scope') else set()
 if set(data)-allowed:refuse('tool.input_invalid')
 host=Host(node)
 return {'apt':apt,'composer':composer,'vp':vp}[manager](host,verb,data)

def main():
 try:
  raw=sys.stdin.buffer.read(2*1024*1024+1)
  if len(raw)>2*1024*1024:refuse('tool.input_invalid')
  value=json.loads(raw);result=dispatch(value)
  if len(json.dumps(result).encode())>LIMIT:refuse('tool.output_invalid')
 except Refusal as error:
  code=error.code
  if code.startswith('tool.scope_'):
   manager=value['node']['manager'] if 'value' in locals() else ''
   code='tool.'+('homebrew' if manager in ('brew','brew-cask') else manager)+'_scope_'+code[11:]
  result={'error_code':code}
 except Exception:result={'error_code':'tool.command_failed'}
 print(json.dumps(result,separators=(',',':')),flush=True)
if __name__=='__main__':main()
`;

export type ToolSystemCall = {
  name: string;
  input: Record<string, unknown>;
  node: z.infer<typeof ToolIntentInput>;
};

/** Run the fixed workflow implementation; never an agent action or caller script. */
async function nativeSystem(
  call: ToolSystemCall,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  signal?.throwIfAborted();
  const linux = call.node.platform === "linux";
  const child = localCommand(linux ? "/usr/bin/sudo" : "/usr/bin/python3", {
    args: linux
      ? ["-n", "--", "/usr/bin/python3", "-c", toolProgram]
      : ["-c", toolProgram],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  let finished = false;
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(call)));
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 4 * 1024 * 1024) {
        throw new Error("Tool output exceeded its bound");
      }
      chunks.push(chunk);
    }
    const status = await child.status;
    finished = true;
    if (!status.success) {
      throw new Error("Native Tool action outcome is unknown");
    }
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    const result = z.record(z.string(), z.unknown()).parse(
      JSON.parse(new TextDecoder("utf-8", { fatal: true }).decode(bytes)),
    );
    if (result.error_code === "tool.action_unknown") {
      throw new Error("Native Tool action requires reconciliation");
    }
    return result;
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* Already exited. */ }
      await child.status.catch(() => {});
    }
  }
}

/** Shared lower adapter for parent DAGs; the admitted account is never replaced by worker HOME. */
export function toolSystem(invoke = nativeSystem) {
  return async (
    name: string,
    input: Record<string, unknown>,
    node: z.infer<typeof ToolIntentInput>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>> => {
    signal?.throwIfAborted();
    return await invoke({ name, input, node }, signal);
  };
}
export const localTool = toolSystem();
