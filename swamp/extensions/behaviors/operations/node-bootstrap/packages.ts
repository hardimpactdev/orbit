import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import {
  installPhpBuild,
  PhpBuildInput,
  PhpLocalError,
} from "../php/build_local.ts";

const Args = DirectArgs.extend({
  kind: z.literal("node.prepare_php_packages"),
  node_id: z.number().int().positive(),
});
const Input = PhpBuildInput.extend({
  extras: z.literal("keep"),
  fpm: z.literal("none"),
}).refine((input) => Object.keys(input.versions).length <= 8);
const Units = z.array(z.string().max(255)).max(256);
const Result = z.object({
  outcome: z.enum(["current", "applied"]).optional(),
  stale_masters: Units.optional(),
  deferred: Units.optional(),
  error_code: z.enum([
    "app-prod.php_package_source_unavailable",
    "app-prod.php_install_failed",
  ]).optional(),
}).strict();
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** Package-only lower: callers compose it before their own runtime and pool steps. */
export async function preparePhpPackages(
  raw: unknown,
  signal?: AbortSignal,
  install = installPhpBuild,
) {
  try {
    const input = Input.parse(raw);
    const result = await install(input, signal);
    return Result.parse({
      outcome: result.outcome,
      stale_masters: result.data.stale_masters,
      deferred: result.data.deferred,
    });
  } catch (error) {
    const source = error instanceof PhpLocalError &&
      [
        "php.build_platform_unsupported",
        "php.build_conflicting_source",
        "php.build_download_failed",
      ].includes(error.code);
    return Result.parse({
      error_code: source
        ? "app-prod.php_package_source_unavailable"
        : "app-prod.php_install_failed",
    });
  }
}
export function phpPackagesDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  prepare = preparePhpPackages,
) {
  return {
    type: "@nckrtl/node-php-packages-direct",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      apply: {
        description:
          "Prepare exact admitted PHP packages without restarting FPM",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            (raw) => prepare(raw.packages, ctx.signal),
            (raw) => Result.parse(raw),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = phpPackagesDirect();
