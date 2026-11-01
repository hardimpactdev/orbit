import { z } from "npm:zod@4.4.3";
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((v) => v !== "root");
const Pin = z.object({ version: z.string(), url: z.url().refine((v) => new URL(v).protocol === "https:"), algorithm: z.enum(["sha256", "sha512"]), digest: z.string().regex(/^[a-f0-9]{64}(?:[a-f0-9]{64})?$/), lock_sha256: z.string().regex(/^[a-f0-9]{64}$/).optional() }).strict();
const Apt = z.object({ snapshot: z.string().regex(/^[0-9]{8}T[0-9]{6}Z$/), packages: z.array(z.object({ name: z.string().regex(/^[a-z0-9][a-z0-9+.-]*$/), version: z.string(), artifact: Pin.omit({ version: true, lock_sha256: true }).nullable() }).strict()) }).strict();
const Packages = z.array(z.string().regex(/^[a-z0-9][a-z0-9+.-]*$/));
export const Setup = z.discriminatedUnion("manager", [
  z.object({ manager: z.literal("javascript"), input: z.object({ user: User, tools: z.object({ node: Pin, "vp-cli": Pin, vp: Pin.required({ lock_sha256: true }), pnpm: Pin }).strict(), vp_lock: z.string() }).strict(), apt: z.null(), packages: Packages }),
  z.object({ manager: z.literal("composer"), input: z.object({ user: User, version: z.string() }).strict(), apt: Apt, packages: Packages }),
  z.object({ manager: z.literal("homebrew"), input: z.object({ user: User, revision: z.string().regex(/^[a-f0-9]{40}$/), version: z.string() }).strict(), apt: z.null(), packages: Packages }),
]);
type Context = { extensionFile(name: string): string; writeResource(spec: string, name: string, value: unknown): Promise<unknown> };
export const behavior = {
  type: "@nckrtl/node-tool-setup-assets", version: "2026.11.01.1",
  resources: { assets: { schema: z.object({ manager_body: z.string(), apt_body: z.string().nullable() }), lifetime: "1d", garbageCollection: 10 } },
  methods: { read: {
    description: "Read the one canonical manager installer and bind admitted public pins",
    arguments: z.object({ setup: Setup }),
    execute: async ({ setup }: { setup: z.infer<typeof Setup> }, ctx: Context) => {
      const bind = async (file: string, input: unknown) => (await Deno.readTextFile(ctx.extensionFile("tool_scripts/" + file))).replace("json.load(sys.stdin)", "json.loads(" + JSON.stringify(JSON.stringify(input)) + ")");
      const manager_body = await bind({ javascript: "install_javascript.py", composer: "prepare_composer.py", homebrew: "install_homebrew.py" }[setup.manager], setup.input);
      const result = { manager_body, apt_body: setup.apt ? await bind("install_apt.py", setup.apt) : null };
      await ctx.writeResource("assets", "assets", result); return result;
    },
  } },
};
