/** Environment for a child process: an allowlist, so agent markers of the launching session never leak in. */
export function cleanEnv(
  get: (k: string) => string | undefined,
  extra: Record<string, string> = {},
): Record<string, string> {
  const env: Record<string, string> = {};
  for (
    const k of [
      "PATH",
      "HOME",
      "USER",
      "LOGNAME",
      "LANG",
      "TMPDIR",
      "COMPOSER_HOME",
      "XDG_CONFIG_HOME",
      "XDG_CACHE_HOME",
    ]
  ) {
    const v = get(k);
    if (v !== undefined) env[k] = v;
  }
  return { ...env, TERM: "dumb", ...extra };
}
