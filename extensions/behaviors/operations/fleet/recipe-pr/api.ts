import { z } from "npm:zod@4.4.3";
import { Repository, requirePolicy, Sha } from "./policy.ts";

/** Fixed GitHub origin/repository. No credential-bearing redirect or error body. */
export class RecipeGitHub {
  constructor(private token: string, private send: typeof fetch = fetch) {}
  async request(
    method: string,
    path: string,
    body?: unknown,
    allowed = [200, 201, 204],
  ) {
    requirePolicy(
      path.startsWith("/") && !path.startsWith("//") && !path.includes("#"),
    );
    const url = path === "/graphql"
      ? "https://api.github.com/graphql"
      : "https://api.github.com/repos/" + Repository + path;
    try {
      const response = await this.send(url, {
        method,
        redirect: "error",
        signal: AbortSignal.timeout(15000),
        headers: {
          Authorization: "Bearer " + this.token,
          Accept: "application/vnd.github+json",
          "X-GitHub-Api-Version": "2022-11-28",
          "Content-Type": "application/json",
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      if (!allowed.includes(response.status)) {
        await response.body?.cancel();
        throw new Error();
      }
      if (response.status === 204) {
        await response.body?.cancel();
        return { status: response.status, data: null as unknown };
      }
      const length = Number(response.headers.get("content-length"));
      if (length > 4 * 1024 * 1024) {
        await response.body?.cancel();
        throw new Error();
      }
      let size = 0;
      const chunks: Uint8Array[] = [];
      for await (const bytes of response.body ?? []) {
        size += bytes.length;
        if (size > 4 * 1024 * 1024) throw new Error();
        chunks.push(bytes);
      }
      const bytes = new Uint8Array(size);
      let offset = 0;
      for (const chunk of chunks) {
        bytes.set(chunk, offset);
        offset += chunk.length;
      }
      return {
        status: response.status,
        data: JSON.parse(new TextDecoder().decode(bytes)) as unknown,
      };
    } catch {
      throw new Error("fleet.recipe_github_unavailable");
    }
  }
  async get(path: string) {
    return (await this.request("GET", path)).data;
  }
  async list(path: string, maximumPages = 20) {
    const result: unknown[] = [];
    for (let page = 1; page <= maximumPages; page++) {
      const separator = path.includes("?") ? "&" : "?";
      const rows = z.array(z.unknown()).max(100).parse(
        await this.get(path + separator + "per_page=100&page=" + page),
      );
      result.push(...rows);
      if (rows.length < 100) return result;
    }
    throw new Error("fleet.recipe_github_truncated");
  }
  async checks(head: string, check: string) {
    Sha.parse(head);
    const rows: unknown[] = [];
    let total: number | undefined;
    for (let page = 1; page <= 20; page++) {
      const result = z.object({
        total_count: z.number().int().nonnegative(),
        check_runs: z.array(z.unknown()).max(100),
      }).parse(
        await this.get(
          "/commits/" + head + "/check-runs?check_name=" +
            encodeURIComponent(check) + "&per_page=100&page=" + page,
        ),
      );
      requirePolicy(total === undefined || total === result.total_count);
      total = result.total_count;
      rows.push(...result.check_runs);
      requirePolicy(rows.length <= total);
      if (rows.length === total) return rows;
      requirePolicy(result.check_runs.length > 0);
    }
    throw new Error("fleet.recipe_github_truncated");
  }
  async content(ref: string) {
    const file = z.object({
      content: z.string(),
      sha: Sha,
      encoding: z.literal("base64"),
    }).parse(
      await this.get(
        "/contents/apps/gateway/resources/machine-software/recipe.json?ref=" +
          encodeURIComponent(ref),
      ),
    );
    const encoded = file.content.replace(/\n/g, "");
    requirePolicy(/^[A-Za-z0-9+/]*={0,2}$/.test(encoded));
    const bytes = Uint8Array.from(
      atob(encoded),
      (character) => character.charCodeAt(0),
    );
    return {
      sha: file.sha,
      content: new TextDecoder("utf-8", { fatal: true }).decode(bytes),
    };
  }
}
