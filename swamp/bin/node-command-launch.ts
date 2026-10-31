/** The sole local envelopes allowed around a light-Node action. */
export function safeNodeEnv(name: string): boolean {
  return /^ORBIT_[A-Z0-9_]{1,58}$/.test(name);
}

export function nodeCommandLaunch(run: unknown): {
  assetStep: string;
  action: string;
  env: string[];
  secretEnv: string[];
  actionAsset?: { step: string; field: string };
} | null {
  if (typeof run !== "string") return null;
  const lines = run.trimEnd().split("\n");
  const head =
    /^\/usr\/bin\/python3 -I "\$\{\{ steps(?:\.([a-zA-Z0-9_-]+)|\["([a-zA-Z0-9_-]+)"\])\.outputs\.runner \}\}" --node-id "\$ORBIT_EFFECT_NODE_ID"/
      .exec(lines[0]);
  if (!head) return null;
  let suffix = lines[0].slice(head[0].length);
  let action = "";
  let actionAsset: { step: string; field: string } | undefined;
  if (lines.length === 1) {
    const file =
      /^ --action-file "\$\{\{ steps(?:\.([a-zA-Z0-9_-]+)|\["([a-zA-Z0-9_-]+)"\])\.outputs\.([a-zA-Z_][a-zA-Z0-9_]*) \}\}" --interpreter python3(?: --as-root)?/
        .exec(suffix);
    if (!file) return null;
    actionAsset = { step: file[1] ?? file[2], field: file[3] };
    suffix = suffix.slice(file[0].length);
  } else {
    if (
      lines.length < 3 || lines.at(-1) !== "ORBIT_ACTION" ||
      lines.slice(1, -1).includes("ORBIT_ACTION")
    ) return null;
    action = lines.slice(1, -1).join("\n") + "\n";
    if (action.includes("${{") || !suffix.endsWith(" <<'ORBIT_ACTION'")) {
      return null;
    }
    suffix = suffix.slice(0, -" <<'ORBIT_ACTION'".length);
  }
  if (
    !/^(?:(?: --(?:env|secret-env) ORBIT_[A-Z0-9_]{1,58})|(?: --(?:timeout-ms|max-output-bytes) [1-9][0-9]*))*$/
      .test(suffix)
  ) return null;
  const env = [
    ...suffix.matchAll(/ --(?:env|secret-env) (ORBIT_[A-Z0-9_]{1,58})/g),
  ].map((m) => m[1]);
  if (new Set(env).size !== env.length) return null;
  const secretEnv = [
    ...suffix.matchAll(/ --secret-env (ORBIT_[A-Z0-9_]{1,58})/g),
  ].map((m) => m[1]);
  return {
    secretEnv,
    assetStep: head[1] ?? head[2],
    action,
    env,
    ...(actionAsset ? { actionAsset } : {}),
  };
}
