import { z } from "npm:zod@4.4.3";
import { RecipeGitHub } from "./api.ts";
import { same } from "./contents.ts";
import {
  appPull,
  Branch,
  mergeMetadata,
  RecipePath,
  requirePolicy,
  Sha,
  untouched,
} from "./policy.ts";
import type { RecipePlan } from "./actions.ts";
const text = (p: RecipePlan, k: string) => z.string().parse(p[k]);
const number = (p: RecipePlan) => z.number().int().positive().parse(p.number);
const sha = (v: unknown) => z.object({ sha: Sha }).parse(v).sha;
/** Exactly one GitHub write per method; reads enforce its public precondition. */
export async function effect(
  method: string,
  github: RecipeGitHub,
  plan: RecipePlan,
  tree?: string,
  commit?: string,
): Promise<Record<string, unknown>> {
  if (method === "branch") {
    Branch.parse(plan.branch);
    await github.request("POST", "/git/refs", {
      ref: "refs/heads/" + plan.branch,
      sha: Sha.parse(plan.main),
    }, [201, 422]);
  } else if (method === "file") {
    const current = await github.content(Branch.parse(plan.branch));
    if (current.content !== plan.content) {
      requirePolicy(current.sha === plan.base_blob);
      const content = btoa(
        String.fromCharCode(
          ...new TextEncoder().encode(text(plan, "content")),
        ),
      );
      await github.request("PUT", "/contents/" + RecipePath, {
        branch: plan.branch,
        sha: current.sha,
        message: plan.title,
        content,
      });
    }
  } else if (method === "pull") {
    const existing = z.array(z.object({ html_url: z.url() })).parse(
      await github.list(
        "/pulls?state=all&head=" +
          encodeURIComponent(
            "hardimpactdev:" + Branch.parse(plan.branch),
          ),
      ),
    );
    if (existing.length) return { url: existing[0].html_url };
    const result = await github.request("POST", "/pulls", {
      head: plan.branch,
      base: "main",
      title: plan.title,
      body: plan.body,
    });
    return {
      url: z.object({ html_url: z.url() }).parse(result.data).html_url,
    };
  } else if (method === "tree") {
    return {
      sha: sha(
        (await github.request("POST", "/git/trees", {
          base_tree: Sha.parse(plan.base_tree),
          tree: [{
            path: RecipePath,
            mode: "100644",
            type: "blob",
            content: text(plan, "content"),
          }],
        })).data,
      ),
    };
  } else if (method === "commit") {
    return {
      sha: sha(
        (await github.request("POST", "/git/commits", {
          message: plan.title,
          tree: Sha.parse(tree),
          parents: [Sha.parse(plan.main)],
        })).data,
      ),
    };
  } else if (method === "ref") {
    const current = await github.get("/pulls/" + number(plan));
    const updated = z.object({ head: z.object({ sha: Sha }) }).parse(
      current,
    );
    if (updated.head.sha === Sha.parse(commit)) return { completed: true };
    requirePolicy(
      same(current, plan.original) &&
        untouched(
          await github.get(
            "/issues/" + number(plan) + "/timeline?per_page=100",
          ),
          text(plan, "author"),
          text(plan, "head"),
        ),
    );
    const response = await github.request("POST", "/graphql", {
      query:
        "mutation($input: UpdateRefsInput!) { updateRefs(input: $input) { clientMutationId } }",
      variables: {
        input: {
          repositoryId: text(plan, "repository_id"),
          refUpdates: [{
            name: "refs/heads/" + Branch.parse(plan.branch),
            beforeOid: Sha.parse(plan.head),
            afterOid: commit,
            force: true,
          }],
        },
      },
    });
    const result = z.object({
      errors: z.unknown().optional(),
      data: z.object({ updateRefs: z.record(z.string(), z.unknown()) }),
    }).parse(response.data);
    requirePolicy(result.errors === undefined);
  } else if (method === "close") {
    const current = z.record(z.string(), z.unknown()).parse(
      await github.get("/pulls/" + number(plan)),
    );
    requirePolicy(
      appPull(
        { ...current, state: "open" },
        text(plan, "head"),
        text(plan, "branch"),
        text(plan, "author"),
      ),
    );
    if (current.state === "closed") return { completed: true };
    await github.request("PATCH", "/pulls/" + number(plan), {
      state: "closed",
    });
  } else if (method === "merge") {
    const current = z.record(z.string(), z.unknown()).parse(
      await github.get("/pulls/" + number(plan)),
    );
    mergeMetadata(
      { ...current, state: "open" },
      text(plan, "head"),
      text(plan, "branch"),
      text(plan, "author"),
    );
    if (current.merged === true) return { completed: true };
    requirePolicy(current.state === "open");
    const result = await github.request(
      "PUT",
      "/pulls/" + number(plan) + "/merge",
      { merge_method: "squash", sha: Sha.parse(plan.head) },
    );
    requirePolicy(
      z.object({ merged: z.boolean() }).parse(result.data).merged,
    );
  } else if (method === "comment") {
    await github.request("POST", "/issues/" + number(plan) + "/comments", {
      body: text(plan, "comment"),
    });
  } else throw new Error("fleet.recipe_pr_policy_refused");
  return { completed: true };
}
