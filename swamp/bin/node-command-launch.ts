/** The sole local envelope allowed around a light-Node action. The body runs remotely. */
export function safeNodeEnv(name: string): boolean {
  return /^ORBIT_[A-Z0-9_]{1,58}$/.test(name) &&
    ![
      "PATH",
      "HOME",
      "USER",
      "LOGNAME",
      "SHELL",
      "ENV",
      "BASH_ENV",
      "SHELLOPTS",
      "IFS",
      "LANG",
    ].includes(name) &&
    !/^(?:SWAMP_|LD_|DYLD_|PYTHON)/.test(name);
}

export function nodeCommandLaunch(
  run: unknown,
): { assetStep: string; action: string; env: string[] } | null {
  if (typeof run !== "string") return null;
  const lines = run.trimEnd().split("\n");
  const action = lines.slice(1, -1).join("\n") + "\n";
  if (
    lines.length < 3 || lines.at(-1) !== "ORBIT_ACTION" ||
    lines.slice(1, -1).includes("ORBIT_ACTION") || action.includes("${{")
  ) return null;
  const match =
    /^\/usr\/bin\/python3 -I "\$\{\{ steps(?:\.([a-zA-Z0-9_-]+)|\["([a-zA-Z0-9_-]+)"\])\.outputs\.runner \}\}" --node-id "\$ORBIT_EFFECT_NODE_ID"(?:(?: --(?:env|secret-env) [A-Z_][A-Z0-9_]{0,63})|(?: --(?:timeout-ms|max-output-bytes) [1-9][0-9]*))* <<'ORBIT_ACTION'$/
      .exec(lines[0]);
  if (!match) return null;
  const env = [
    ...lines[0].matchAll(/ --(?:env|secret-env) ([A-Z_][A-Z0-9_]{0,63})/g),
  ].map((m) => m[1]);
  if (
    new Set(env).size !== env.length ||
    env.some((name) => !safeNodeEnv(name))
  ) return null;
  return { assetStep: match[1] ?? match[2], action, env };
}
