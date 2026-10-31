import { z } from "npm:zod@4.4.3";
import { RecipeProgram } from "./recipe_program.ts";

const ObjectInput = z.record(z.string(), z.unknown());
const Refusal = z.object({
  error_code: z.string().regex(/^[a-z][a-z0-9_]*\.[a-z0-9_.-]{1,90}$/),
}).strict();
export type RecipeProgramCall = {
  program: string;
  input: Record<string, unknown>;
  mutates: boolean;
  timeoutMs: number;
};
export type RecipeProgramRunner = (
  call: RecipeProgramCall,
  signal?: AbortSignal,
) => Promise<unknown>;

/** Source-owned fixed Python program, never caller script or an agent action. */
export const runRecipeProgram: RecipeProgramRunner = async (call, signal) => {
  signal?.throwIfAborted();
  const timeout = AbortSignal.timeout(call.timeoutMs);
  const child = new Deno.Command("/usr/bin/sudo", {
    args: ["-n", "--", "/usr/bin/python3", "-c", call.program],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: call.mutates || !signal
      ? timeout
      : AbortSignal.any([timeout, signal]),
  }).spawn();
  let finished = false;
  try {
    const write = async () => {
      const writer = child.stdin.getWriter();
      try {
        await writer.write(
          new TextEncoder().encode(JSON.stringify(call.input) + "\n"),
        );
        await writer.close();
      } finally {
        writer.releaseLock();
      }
    };
    const read = async () => {
      const chunks: Uint8Array[] = [];
      let size = 0;
      for await (const chunk of child.stdout) {
        size += chunk.length;
        if (size > 65536) {
          throw new Error("Local recipe output exceeded its bound");
        }
        chunks.push(chunk);
      }
      return { chunks, size };
    };
    const [, { chunks, size }, status] = await Promise.all([
      write(),
      read(),
      child.status,
    ]);
    finished = true;
    timeout.throwIfAborted();
    if (!status.success) {
      throw new Error("Local recipe checkpoint has no certain result");
    }
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return ObjectInput.parse(JSON.parse(new TextDecoder().decode(bytes)));
  } finally {
    if (!finished) {
      try {
        child.kill("SIGTERM");
      } catch { /* Already exited. */ }
      await child.status.catch(() => {});
    }
  }
};
const IncusEntry = String.raw`
try:
    result=incus_preflight(RecipeHost(),json.load(sys.stdin))
except RecipeRefusal as error:
    result={'error_code':error.code}
except Exception:
    sys.exit(1)
print(json.dumps(result,separators=(',',':')))
`;

/** Exact recipe Incus pin/source checks and all-project drained-host observation. */
export async function incusRecipePreflight(
  input: Record<string, unknown>,
  { signal, run = runRecipeProgram }: {
    signal?: AbortSignal;
    run?: RecipeProgramRunner;
  } = {},
) {
  const raw = await run({
    program: RecipeProgram + IncusEntry,
    input,
    mutates: false,
    timeoutMs: 900000,
  }, signal);
  return z.union([z.object({ ready: z.literal(true) }).strict(), Refusal])
    .parse(raw);
}

const JavascriptResult = z.object({
  changed: z.boolean(),
  retired: z.array(z.string()).max(512),
}).strict();
const LocalRefusal = Refusal.extend({ restored: z.boolean().optional() });

type ProgramOptions = {
  signal?: AbortSignal;
  run?: RecipeProgramRunner;
};

const JavascriptEntry = String.raw`
try:
    result=install_javascript(RecipeHost(),json.load(sys.stdin))
except RecipeRefusal as error:
    result={'error_code':error.code}
except Exception:
    sys.exit(1)
print(json.dumps(result,separators=(',',':')))
`;

/** The parent run installs signed-recipe JavaScript artifacts directly on its Node. */
export async function installRecipeJavascript(
  input: Record<string, unknown>,
  { signal, run = runRecipeProgram }: ProgramOptions = {},
) {
  const raw = await run({
    program: RecipeProgram + JavascriptEntry,
    input,
    mutates: true,
    timeoutMs: 900000,
  }, signal);
  return z.union([JavascriptResult, LocalRefusal]).parse(raw);
}

const PhpBuildData = z.object({
  changed: z.array(z.string()).max(512),
  repaired: z.array(z.string()).max(512),
  purged: z.array(z.string()).max(512),
  disabled: z.array(z.string()).max(512),
  restarted: z.array(z.string()).max(512),
  reloaded: z.array(z.string()).max(512),
  deferred: z.array(z.string()).max(512),
  stale_masters: z.array(z.string()).max(512),
  fpm_disabled: z.boolean(),
}).strict();
const PhpBuildEntry = String.raw`
try:
    result=install_php_build(RecipeHost(),json.load(sys.stdin))
except RecipeRefusal as error:
    result={'error_code':error.code}
    if hasattr(error,'restored'):result['restored']=error.restored
except Exception:
    sys.exit(1)
print(json.dumps(result,separators=(',',':')))
`;

/** Install exact PHP debs, preserve rollback copies, and verify modules and active pools. */
export async function installRecipePhpBuild(
  input: Record<string, unknown>,
  { signal, run = runRecipeProgram }: ProgramOptions = {},
) {
  const raw = await run({
    program: RecipeProgram + PhpBuildEntry,
    input,
    mutates: true,
    timeoutMs: 900000,
  }, signal);
  return z.union([
    z.object({
      outcome: z.enum(["current", "applied"]),
      data: PhpBuildData,
    }).strict(),
    LocalRefusal,
  ]).parse(raw);
}

const PhpPlanEntry = String.raw`
try:
    result=prepare_php(RecipeHost(),json.load(sys.stdin))['plan']
except RecipeRefusal as error:
    result={'error_code':error.code}
except Exception:
    sys.exit(1)
print(json.dumps(result,separators=(',',':')))
`;

/** Inspect the worker's exact package/module ownership without invoking orbit-agent. */
export async function inspectRecipePhpBuild(
  input: Record<string, unknown>,
  { signal, run = runRecipeProgram }: ProgramOptions = {},
) {
  const raw = await run({
    program: RecipeProgram + PhpPlanEntry,
    input,
    mutates: false,
    timeoutMs: 900000,
  }, signal);
  return z.union([
    z.object({
      pending: z.array(z.string()).max(512),
      repair: z.array(z.string()).max(512),
      extras: z.array(z.string()).max(512),
      unowned: z.array(z.string()).max(512),
    }).strict(),
    LocalRefusal,
  ]).parse(raw);
}

const RecipeInspectEntry = String.raw`
try:
    result=inspect_recipe(RecipeHost(),json.load(sys.stdin))
except RecipeRefusal as error:
    result={'error_code':error.code}
except Exception:
    sys.exit(1)
print(json.dumps(result,separators=(',',':')))
`;

/** Inspect local package provenance and runtime pins on the placed worker. */
export async function inspectRecipe(
  input: Record<string, unknown>,
  { signal, run = runRecipeProgram }: ProgramOptions = {},
) {
  return ObjectInput.parse(
    await run({
      program: RecipeProgram + RecipeInspectEntry,
      input,
      mutates: false,
      timeoutMs: 900000,
    }, signal),
  );
}

const RecipeApplyEntry = String.raw`
try:
    result=apply_recipe(RecipeHost(),json.load(sys.stdin))
except RecipeRefusal as error:
    result={'error_code':error.code}
except Exception:
    sys.exit(1)
print(json.dumps(result,separators=(',',':')))
`;

/** Apply recipe packages and runtimes directly, then verify affected service health. */
export async function applyRecipe(
  input: Record<string, unknown>,
  { signal, run = runRecipeProgram }: ProgramOptions = {},
) {
  const raw = await run({
    program: RecipeProgram + RecipeApplyEntry,
    input,
    mutates: true,
    timeoutMs: 900000,
  }, signal);
  return z.union([
    z.object({
      changed_packages: z.array(z.string()).max(512),
      changed_tools: z.array(z.string()).max(512),
      restarted_services: z.array(z.string()).max(512),
      newer_than_recipe: z.record(
        z.string(),
        z.object({ installed: z.string(), version: z.string() }).strict(),
      ),
      healthy: z.literal(true),
    }),
    z.object({ healthy: z.literal(false) }).strict(),
    LocalRefusal,
  ]).parse(raw);
}

const RecipeDeliveryEntry = String.raw`
try:
    result=verify_recipe_delivery(RecipeHost(),json.load(sys.stdin))
except RecipeRefusal as error:
    result={'error_code':error.code}
except Exception:
    sys.exit(1)
print(json.dumps(result,separators=(',',':')))
`;

/** Read only the exact installed delivery under the canonical update lock. */
export async function verifyRecipeDelivery(
  input: Record<string, unknown>,
  { signal, run = runRecipeProgram }: ProgramOptions = {},
) {
  return ObjectInput.parse(
    await run({
      program: RecipeProgram + RecipeDeliveryEntry,
      input,
      mutates: false,
      timeoutMs: 365000,
    }, signal),
  );
}
