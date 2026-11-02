import { ciCacheImportProgram } from "../instance-deploy/app_step_runtime.ts";

/**
 * One Project setup or teardown command as the official `@swamp/ssh exec`
 * argv that runs it in the task workspace checkout, as the SSH user. A task
 * VM has no seed, so the seed variables are empty. The Gateway-owned
 * `@orbit/ci-caches` step expands to its shared program. Nothing runs here.
 */
export function lifecycleArgv(
  checkout: string,
  user: string,
  command: string,
): string[] {
  return [
    "env",
    "-C",
    checkout,
    "COMPOSER_NO_INTERACTION=1",
    "ORBIT_CHECK_PROCESSES=1",
    "COMPOSER_CACHE_DIR=/home/" + user + "/.cache/composer",
    "ORBIT_SEED_PATH=",
    "ORBIT_SEED_COMMIT=",
    "/bin/bash",
    "-lc",
    command === "@orbit/ci-caches" ? ciCacheImportProgram : command,
  ];
}
