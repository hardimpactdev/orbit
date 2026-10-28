import { AsyncLocalStorage } from "node:async_hooks";

export type ToolPolicy = { home: string; npmrc: string; bunfig: string };
const policies = new AsyncLocalStorage<ToolPolicy>();
export const serviceToolPolicy = () => policies.getStore();
export const withServiceTools = <T>(
  policy: ToolPolicy,
  run: () => Promise<T>,
) => policies.run(policy, run);

/** Only the Services scanner opts in. VM execution retains the project's own configuration. */
export function toolOptions(
  binary: string,
  args: string[],
  env: Record<string, string>,
) {
  const policy = serviceToolPolicy();
  if (!policy) return { args, env };
  const trusted = {
    PATH: env.PATH ?? "/usr/bin:/bin",
    LANG: "C.UTF-8",
    TERM: "dumb",
    HOME: policy.home,
    XDG_CONFIG_HOME: policy.home,
    XDG_CACHE_HOME: `${policy.home}/cache`,
    COMPOSER_HOME: policy.home,
    COMPOSER_NO_INTERACTION: "1",
    COMPOSER_ALLOW_SUPERUSER: "0",
    COMPOSER_DISABLE_NETWORK: "0",
    COMPOSER_CACHE_DIR: `${policy.home}/composer-cache`,
    GIT_CONFIG_NOSYSTEM: "1",
    GIT_CONFIG_GLOBAL: "/dev/null",
    GIT_TERMINAL_PROMPT: "0",
    npm_config_registry: "https://registry.npmjs.org/",
    npm_config_git: "git",
    npm_config_userconfig: policy.npmrc,
    npm_config_globalconfig: "/dev/null",
    npm_config_ignore_scripts: "true",
    npm_config_cache: `${policy.home}/npm-cache`,
    BUN_CONFIG_NO_CLEAR_TERMINAL: "1",
    BUN_INSTALL_CACHE_DIR: `${policy.home}/bun-cache`,
  };
  return {
    args: binary === "bun" ? ["--config", policy.bunfig, ...args] : args,
    env: trusted,
  };
}
