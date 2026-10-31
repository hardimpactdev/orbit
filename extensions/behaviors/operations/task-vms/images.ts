import { ExecutorError } from "../../operation-runner/mod.ts";

export const recipePhases = [
  "apt-source",
  "packages",
  "javascript",
  "rust",
  "agent",
  "pi",
] as const;
export async function installWithRetry(
  key: string,
  install: (key: string) => Promise<unknown>,
) {
  for (let attempt = 0;; attempt++) {
    try {
      await install(attempt === 0 ? key : `${key}-retry-${attempt}`);
      return;
    } catch (error) {
      if (
        !(error instanceof ExecutorError) ||
        error.code !== "agent.supervisor_not_ready"
      ) throw error;
    }
  }
}
