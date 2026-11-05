import { ciCacheImportProgram } from "../instance-deploy/app_step_runtime.ts";

/**
 * One Project setup, teardown or check command as the official `@swamp/ssh
 * exec` argv that runs it in a task workspace directory, as the SSH user. A
 * task VM has no seed, so the seed variables are empty. `environment` adds
 * fixed `NAME=value` pairs, such as the Instance's SSR port. The
 * Gateway-owned `@orbit/ci-caches` step expands to its shared program.
 * Nothing runs here.
 */
export function lifecycleArgv(
    directory: string,
    user: string,
    command: string,
    environment: string[] = [],
): string[] {
    return [
        "env",
        "-C",
        directory,
        "COMPOSER_NO_INTERACTION=1",
        "ORBIT_CHECK_PROCESSES=1",
        "COMPOSER_CACHE_DIR=/home/" + user + "/.cache/composer",
        "ORBIT_SEED_PATH=",
        "ORBIT_SEED_COMMIT=",
        ...environment,
        "/bin/bash",
        "-lc",
        command === "@orbit/ci-caches" ? ciCacheImportProgram : command,
    ];
}
