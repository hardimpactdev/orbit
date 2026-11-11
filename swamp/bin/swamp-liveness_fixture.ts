/** JSON-only Python driver for functions without a command-line interface. */
import { assertEquals } from "jsr:@std/assert@1.0.19";

export async function python(source: string) {
    const script = new URL("./swamp-liveness.py", import.meta.url).pathname;
    const result = await new Deno.Command("python3", {
        args: [
            "-c",
            `import importlib.util,json,tempfile
from pathlib import Path
from unittest.mock import patch
spec=importlib.util.spec_from_file_location("liveness",${
                JSON.stringify(script)
            })
liveness=importlib.util.module_from_spec(spec)
spec.loader.exec_module(liveness)
def error(call):
 try: call()
 except Exception as e: return type(e).__name__
 return None
${source}`,
        ],
        stdout: "piped",
        stderr: "piped",
    }).output();
    assertEquals(result.code, 0, new TextDecoder().decode(result.stderr));
    return JSON.parse(new TextDecoder().decode(result.stdout));
}

export const evaluate = `
def evaluate(previous=None, **changes):
 values=dict(now=2000,healthy=True,heads={"fleet.peer_dns":1000},free_inodes=200,total_inodes=1000,serve_age=1000)
 values.update(changes)
 return liveness.evaluate(previous or {},**values)
`;
