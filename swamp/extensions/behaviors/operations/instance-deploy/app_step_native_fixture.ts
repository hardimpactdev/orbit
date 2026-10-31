/** Synthetic native integration fixture; never loaded as a behavior. */
import { AppStep, sha256, sudoAppStep } from "./app_step.ts";
const raw = JSON.parse(await new Response(Deno.stdin.readable).text());
const script = String(raw.script);
const step = AppStep.parse({
  project_id: 99009,
  instance_id: 99012,
  target: { variant: "release", release: "proof-1" },
  name: "probe",
  phase: "setup",
  command_digest: await sha256(script),
  timeout_seconds: raw.seconds ?? 2,
  ...raw.changes,
});
const abort = new AbortController();
const timer = raw.abort_after_ms
  ? setTimeout(() => abort.abort(), raw.abort_after_ms)
  : undefined;
try {
  console.log(JSON.stringify(await sudoAppStep(step, script, abort.signal)));
} finally {
  if (timer !== undefined) clearTimeout(timer);
}
