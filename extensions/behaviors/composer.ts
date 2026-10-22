// @nckrtl/composer — Composer in one project directory, with typed results.
// The registry has no Composer extension (searched 2026-10-22). Child processes
// get an allowlisted environment, so agent markers (CLAUDECODE, AI_AGENT) of the
// session that started swamp never switch Pest to agent output.
import { z } from "npm:zod@4";
import { cleanEnv, type LockChange, lockChanges, tail } from "./_lib/composer_lock.ts";

const ResultSchema = z.object({
  dir: z.string(),
  command: z.string(),
  exitCode: z.number().int(),
  passed: z.boolean(),
  outputTail: z.string(),
  durationMs: z.number(),
  lockfileChanged: z.boolean().optional(),
  packages: z.array(z.object({ name: z.string(), from: z.string().nullable(), to: z.string().nullable() })).optional(),
});

type Ctx = {
  writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
  logger: { info: (msg: string, props?: Record<string, unknown>) => void };
  signal?: AbortSignal;
};

async function composer(dir: string, args: string[], signal?: AbortSignal) {
  const started = Date.now();
  const out = await new Deno.Command("composer", {
    args: [...args, "--no-interaction", "--no-ansi"],
    cwd: dir,
    clearEnv: true,
    env: cleanEnv((k) => Deno.env.get(k)),
    stdout: "piped",
    stderr: "piped",
    signal,
  }).output();
  const text = new TextDecoder().decode(out.stdout) + new TextDecoder().decode(out.stderr);
  return { exitCode: out.code, output: text, durationMs: Date.now() - started };
}

function readLock(dir: string): Record<string, unknown> {
  try {
    return JSON.parse(Deno.readTextFileSync(`${dir}/composer.lock`));
  } catch {
    return {};
  }
}

function slug(s: string): string {
  return s.replace(/[^a-zA-Z0-9]+/g, "-").replace(/^-|-$/g, "");
}

export const behavior = {
  type: "@nckrtl/composer",
  version: "2026.10.22.1",
  globalArguments: z.object({}),
  resources: {
    result: { description: "Outcome of one Composer command", schema: ResultSchema, lifetime: "30d", garbageCollection: 20 },
  },
  methods: {
    install: {
      description: "composer install from the lockfile",
      arguments: z.object({ dir: z.string(), key: z.string() }),
      execute: async (args: { dir: string; key: string }, ctx: Ctx) => {
        const r = await composer(args.dir, ["install", "--no-progress", "-q"], ctx.signal);
        if (r.exitCode !== 0) throw new Error(`composer install in ${args.dir} failed:\n${tail(r.output, 4000)}`);
        const h = await ctx.writeResource("result", `install-${args.key}-${slug(args.dir)}`, {
          dir: args.dir, command: "install", exitCode: 0, passed: true, outputTail: tail(r.output, 2000), durationMs: r.durationMs,
        });
        return { dataHandles: [h] };
      },
    },
    update: {
      description: "composer update within the declared constraints; records every locked package that moved",
      arguments: z.object({ dir: z.string(), key: z.string() }),
      execute: async (args: { dir: string; key: string }, ctx: Ctx) => {
        const before = readLock(args.dir);
        const r = await composer(args.dir, ["update", "--no-progress", "--no-audit"], ctx.signal);
        const packages: LockChange[] = r.exitCode === 0 ? lockChanges(before, readLock(args.dir)) : [];
        ctx.logger.info("composer update {dir}: exit {code}, {n} packages moved", { dir: args.dir, code: r.exitCode, n: packages.length });
        const h = await ctx.writeResource("result", `update-${args.key}`, {
          dir: args.dir, command: "update", exitCode: r.exitCode, passed: r.exitCode === 0, outputTail: tail(r.output),
          durationMs: r.durationMs, lockfileChanged: packages.length > 0, packages,
        });
        return { dataHandles: [h] };
      },
    },
    script: {
      description: "Run one composer script (e.g. check, test); a failing script is a result, not an error",
      arguments: z.object({ dir: z.string(), key: z.string(), name: z.string() }),
      execute: async (args: { dir: string; key: string; name: string }, ctx: Ctx) => {
        const r = await composer(args.dir, [args.name], ctx.signal);
        ctx.logger.info("composer {name} {dir}: exit {code} in {s}s", { name: args.name, dir: args.dir, code: r.exitCode, s: Math.round(r.durationMs / 1000) });
        const h = await ctx.writeResource("result", `script-${args.key}-${args.name}`, {
          dir: args.dir, command: args.name, exitCode: r.exitCode, passed: r.exitCode === 0, outputTail: tail(r.output), durationMs: r.durationMs,
        });
        return { dataHandles: [h] };
      },
    },
  },
};
