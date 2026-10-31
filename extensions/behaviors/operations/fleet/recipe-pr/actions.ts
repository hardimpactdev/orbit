import { z } from "npm:zod@4.4.3";
import { RecipeGitHub } from "./api.ts";
import {
  artifactFingerprint,
  isDeployed,
  parseRecipe,
  same,
  validateArtifactChange,
} from "./contents.ts";
import { artifactIndexes, bumpSnapshot } from "./indexes.ts";
import {
  appParent,
  appPull,
  Branch,
  checkState,
  currentDate,
  mergeMetadata,
  nightlySnapshot,
  RecipePath,
  requirePolicy,
  Sha,
  snapshotPatch,
  trustedFailedChecks,
  untouched,
} from "./policy.ts";

export const RecipeIntent = z.object({
  action: z.enum(["open", "update", "merge", "retire"]),
  variant: z.enum(["snapshot", "artifacts"]),
  date: z.string().regex(/^[0-9]{8}$/),
  pull_number: z.number().int().nonnegative(),
  head_sha: z.string(),
  author: z.string().regex(/^[a-zA-Z0-9-]+\[bot\]$/),
  required_check: z.string().min(1).max(100),
  deployed_recipe: z.string().max(2 * 1024 * 1024),
}).strict().refine((input) =>
  input.action === "open"
    ? input.pull_number === 0 && input.head_sha === ""
    : input.pull_number > 0 && Sha.safeParse(input.head_sha).success
);
const Listed = z.object({
  number: z.number().int().positive(),
  html_url: z.url(),
  user: z.object({ login: z.string(), type: z.string() }),
  head: z.object({ ref: z.string(), sha: Sha }),
});
type ListedPull = z.infer<typeof Listed>;
type Input = z.infer<typeof RecipeIntent>;
export const OpenPlan = z.object({
  intent: RecipeIntent,
  main: Sha,
  base_blob: Sha,
  content: z.string().max(2 * 1024 * 1024),
  branch: Branch,
  title: z.string().min(1).max(256),
}).strict();
export type OpenPlanStore = {
  read(): Promise<unknown>;
  write(plan: z.infer<typeof OpenPlan>): Promise<void>;
};
type Indexes = {
  snapshot: typeof bumpSnapshot;
  artifacts: typeof artifactIndexes;
};
type RecipeOutcome = {
  completed: true;
  outcome: "checked" | "absent";
  url?: string;
} | { error_code: string };
const checked = { completed: true, outcome: "checked" } as const;
const absent = { completed: true, outcome: "absent" } as const;
const snapshotBody =
  "Advances the authenticated Ubuntu snapshot and Ubuntu pins. Swamp checks App authorship, the signed single commit, exact recipe-only change and required checks before merge. Image builds gate fleet convergence.";
const artifactBody =
  "Updates complete Sury PHP and Incus LTS sets from authenticated indexes. Swamp verifies the App commit, recipe-only change, pin-derived branch, authenticated candidate and required checks before merge. Image builds gate fleet convergence; Incus changes require explicit convergence on a drained host.";

/** One admitted GitHub action. Native workflow recovery owns uncertain HTTP writes. */
export class RecipePrAction {
  constructor(
    private github: RecipeGitHub,
    private indexes: Indexes = {
      snapshot: bumpSnapshot,
      artifacts: artifactIndexes,
    },
    private plans: OpenPlanStore,
  ) {}
  async execute(raw: unknown): Promise<RecipeOutcome> {
    const input = RecipeIntent.parse(raw);
    if (input.date !== currentDate()) {
      return { error_code: "fleet.recipe_pr_stale" };
    }
    if (input.action === "open") return await this.open(input);
    const pulls = z.array(Listed).parse(
      await this.github.list("/pulls?state=open&sort=created&direction=asc"),
    );
    const pull = pulls.find((pull) =>
      pull.number === input.pull_number && pull.head.sha === input.head_sha
    );
    if (!pull) return absent;
    const main = (await this.github.content("main")).content;
    if (input.action === "retire") {
      await this.retire(input, pull, pulls, main);
      return checked;
    }
    if (input.action === "update") {
      await this.refresh(input, pull);
      return checked;
    }
    if (await this.retire(input, pull, pulls, main)) return checked;
    return await this.merge(input, pull);
  }
  private async open(input: Input) {
    const saved = await this.plans.read();
    if (saved !== null && saved !== undefined) {
      const plan = OpenPlan.parse(saved);
      requirePolicy(same(plan.intent, input));
      return await this.publishOpen(plan);
    }
    const head = Sha.parse(
      z.object({ object: z.object({ sha: Sha }) }).parse(
        await this.github.get("/git/ref/heads/main"),
      ).object.sha,
    );
    const main = await this.github.content(head);
    if (!isDeployed(main.content, input.deployed_recipe)) {
      return { error_code: "fleet.recipe_pending_deployment" };
    }
    const recipe = parseRecipe(main.content),
      snapshot = input.date + "T000000Z";
    if (input.variant === "snapshot" && recipe.ubuntu.snapshot >= snapshot) {
      return checked;
    }
    const updated = input.variant === "snapshot"
      ? await this.indexes.snapshot(main.content, snapshot)
      : await this.indexes.artifacts(main.content, true);
    if (updated === main.content) return checked;
    parseRecipe(updated);
    if (input.variant === "artifacts") {
      validateArtifactChange(main.content, updated);
    }
    const branch = input.variant === "snapshot"
      ? "nightly/recipe-" + input.date
      : "nightly/artifacts-" + await artifactFingerprint(updated);
    const plan = OpenPlan.parse({
      intent: input,
      main: head,
      base_blob: main.sha,
      content: updated,
      branch,
      title: input.variant === "snapshot"
        ? "Bump machine recipe snapshot to " + snapshot
        : "Bump the recipe artifacts (Sury PHP and Incus LTS)",
    });
    // Native retry must keep its first authenticated candidate even if the
    // upstream package indexes advance after a committed write loses its reply.
    await this.plans.write(plan);
    return await this.publishOpen(plan);
  }
  private async publishOpen(plan: z.infer<typeof OpenPlan>) {
    const { intent: input, branch, content: updated, title } = plan;
    parseRecipe(updated);
    requirePolicy(
      branch ===
        (input.variant === "snapshot"
          ? "nightly/recipe-" + input.date
          : "nightly/artifacts-" + await artifactFingerprint(updated)),
    );
    const existing = z.array(Listed).parse(
      await this.github.list(
        "/pulls?state=all&head=" +
          encodeURIComponent("hardimpactdev:" + branch),
      ),
    );
    if (existing.length) return { ...checked, url: existing[0].html_url };
    await this.github.request("POST", "/git/refs", {
      ref: "refs/heads/" + branch,
      sha: plan.main,
    }, [201, 422]);
    const onBranch = await this.github.content(branch);
    if (onBranch.content !== updated) {
      requirePolicy(onBranch.sha === plan.base_blob);
      await this.github.request("PUT", "/contents/" + RecipePath, {
        branch,
        sha: plan.base_blob,
        message: title,
        content: encode(updated),
      });
    }
    const pull = z.object({ html_url: z.url() }).parse(
      (await this.github.request("POST", "/pulls", {
        head: branch,
        base: "main",
        title,
        body: input.variant === "snapshot" ? snapshotBody : artifactBody,
      })).data,
    );
    return { ...checked, url: pull.html_url };
  }
  private async retire(
    input: Input,
    pull: ListedPull,
    pulls: ListedPull[],
    main: string,
  ) {
    const date = nightlySnapshot(pull.head.ref);
    if (!date || pull.user.login !== input.author) return false;
    let reason = parseRecipe(main).ubuntu.snapshot >= date
      ? "main's Ubuntu snapshot has reached this PR's date."
      : null;
    if (!reason) {
      for (const newer of pulls) {
        const next = nightlySnapshot(newer.head.ref);
        if (!next || next <= date || newer.user.login !== input.author) {
          continue;
        }
        if (
          appPull(
            await this.github.get("/pulls/" + newer.number),
            newer.head.sha,
            newer.head.ref,
            input.author,
          )
        ) {
          reason = "a newer App nightly PR is open: " + newer.html_url;
          break;
        }
      }
    }
    if (
      !reason ||
      !appPull(
        await this.github.get("/pulls/" + pull.number),
        pull.head.sha,
        pull.head.ref,
        input.author,
      )
    ) return false;
    await this.github.request("PATCH", "/pulls/" + pull.number, {
      state: "closed",
    });
    await this.comment(
      pull.number,
      "Superseded this nightly recipe PR because " + reason,
    );
    return true;
  }
  private async merge(input: Input, pull: ListedPull) {
    if (!Branch.safeParse(pull.head.ref).success) return checked;
    const artifact = pull.head.ref.startsWith("nightly/artifacts-");
    const checks = await this.github.checks(
      pull.head.sha,
      input.required_check,
    );
    const green =
      checkState(checks, pull.head.sha, input.required_check) === "passed";
    if (!artifact && !green) return checked;
    const path = "/pulls/" + pull.number;
    mergeMetadata(
      await this.github.get(path),
      pull.head.sha,
      pull.head.ref,
      input.author,
    );
    const parent = appParent(
      await this.github.get(path + "/commits?per_page=2"),
      pull.head.sha,
      input.author,
    );
    const files = await this.github.get(path + "/files?per_page=2");
    const before = (await this.github.content(parent)).content,
      after = (await this.github.content(pull.head.sha)).content;
    parseRecipe(before);
    parseRecipe(after);
    if (artifact) {
      const rows = z.array(
        z.object({ filename: z.string(), status: z.string() }),
      ).parse(files);
      requirePolicy(
        rows.length === 1 && rows[0].filename === RecipePath &&
          rows[0].status === "modified",
      );
      validateArtifactChange(before, after);
      requirePolicy(
        pull.head.ref ===
          "nightly/artifacts-" + await artifactFingerprint(after),
      );
      const latest = await this.indexes.artifacts(before);
      if (latest !== before) validateArtifactChange(before, latest);
      if (latest !== after) {
        mergeMetadata(
          await this.github.get(path),
          pull.head.sha,
          pull.head.ref,
          input.author,
        );
        await this.github.request("PATCH", path, { state: "closed" });
        return checked;
      }
    } else {
      const snapshot = nightlySnapshot(pull.head.ref)!;
      requirePolicy(
        snapshot > parseRecipe(before).ubuntu.snapshot &&
          parseRecipe(after).ubuntu.snapshot === snapshot,
      );
      snapshotPatch(
        files,
        before,
        after,
        await this.indexes.snapshot(before, snapshot),
      );
    }
    mergeMetadata(
      await this.github.get(path),
      pull.head.sha,
      pull.head.ref,
      input.author,
    );
    if (!green) return checked;
    const merged = z.object({ merged: z.boolean() }).parse(
      (await this.github.request("PUT", path + "/merge", {
        merge_method: "squash",
        sha: pull.head.sha,
      })).data,
    );
    requirePolicy(merged.merged);
    return checked;
  }
  private async refresh(input: Input, pull: ListedPull) {
    const snapshot = nightlySnapshot(pull.head.ref);
    if (
      snapshot !== input.date + "T000000Z" || pull.user.login !== input.author
    ) return;
    const path = "/pulls/" + pull.number,
      original = await this.github.get(path);
    const pr = appPull(original, pull.head.sha, pull.head.ref, input.author);
    if (!pr || pr.draft || pr.commits !== 1 || pr.changed_files !== 1) return;
    const checks = await this.github.checks(
      pull.head.sha,
      input.required_check,
    );
    if (!trustedFailedChecks(checks, pull.head.sha, input.required_check)) {
      return;
    }
    const parent = appParent(
      await this.github.get(path + "/commits?per_page=2"),
      pull.head.sha,
      input.author,
    );
    const main = z.object({ object: z.object({ sha: Sha }) }).parse(
      await this.github.get("/git/ref/heads/main"),
    ).object.sha;
    if (
      parent === main ||
      z.object({ status: z.string() }).parse(
          await this.github.get("/compare/" + parent + "..." + main),
        ).status !== "ahead"
    ) return;
    const files = z.array(
      z.object({ filename: z.string(), status: z.string() }),
    ).parse(await this.github.get(path + "/files?per_page=2"));
    if (
      files.length !== 1 || files[0].filename !== RecipePath ||
      files[0].status !== "modified"
    ) return;
    const before = (await this.github.content(parent)).content,
      after = (await this.github.content(pull.head.sha)).content;
    if (
      parseRecipe(before).ubuntu.snapshot >= snapshot! ||
      await this.indexes.snapshot(before, snapshot!) !== after
    ) return;
    if (
      !untouched(
        await this.github.get(
          "/issues/" + pull.number + "/timeline?per_page=100",
        ),
        input.author,
        pull.head.sha,
      )
    ) return;
    const history = await this.github.request("POST", "/graphql", {
      query:
        "query($owner: String!, $name: String!, $number: Int!) { repository(owner: $owner, name: $name) { id pullRequest(number: $number) { userContentEdits(first: 100) { nodes { editor { login } } pageInfo { hasNextPage } } } } }",
      variables: { owner: "hardimpactdev", name: "orbit", number: pull.number },
    });
    const graph = z.object({
      errors: z.unknown().optional(),
      data: z.object({
        repository: z.object({
          id: z.string(),
          pullRequest: z.object({
            userContentEdits: z.object({
              nodes: z.array(
                z.object({ editor: z.object({ login: z.string() }) }),
              ),
              pageInfo: z.object({ hasNextPage: z.boolean() }),
            }),
          }),
        }),
      }),
    }).parse(history.data);
    if (
      graph.errors !== undefined ||
      graph.data.repository.pullRequest.userContentEdits.pageInfo.hasNextPage ||
      graph.data.repository.pullRequest.userContentEdits.nodes.some((edit) =>
        edit.editor.login !== input.author
      )
    ) return;
    const content = (await this.github.content(main)).content;
    const updated = await this.indexes.snapshot(content, snapshot!);
    parseRecipe(updated);
    const baseTree = z.object({ tree: z.object({ sha: Sha }) }).parse(
      await this.github.get("/git/commits/" + main),
    ).tree.sha;
    const tree = z.object({ sha: Sha }).parse(
      (await this.github.request("POST", "/git/trees", {
        base_tree: baseTree,
        tree: [{
          path: RecipePath,
          mode: "100644",
          type: "blob",
          content: updated,
        }],
      })).data,
    ).sha;
    const commit = z.object({ sha: Sha }).parse(
      (await this.github.request("POST", "/git/commits", {
        message: "Bump machine recipe snapshot to " + snapshot,
        tree,
        parents: [main],
      })).data,
    ).sha;
    if (
      !same(await this.github.get(path), original) ||
      !untouched(
        await this.github.get(
          "/issues/" + pull.number + "/timeline?per_page=100",
        ),
        input.author,
        pull.head.sha,
      )
    ) return;
    const result = await this.github.request("POST", "/graphql", {
      query:
        "mutation($input: UpdateRefsInput!) { updateRefs(input: $input) { clientMutationId } }",
      variables: {
        input: {
          repositoryId: graph.data.repository.id,
          refUpdates: [{
            name: "refs/heads/" + pull.head.ref,
            beforeOid: pull.head.sha,
            afterOid: commit,
            force: true,
          }],
        },
      },
    });
    const ref = z.object({
      errors: z.unknown().optional(),
      data: z.object({ updateRefs: z.record(z.string(), z.unknown()) }),
    }).parse(result.data);
    requirePolicy(ref.errors === undefined);
    await this.comment(
      pull.number,
      "Recreated the failed nightly snapshot on current main as one fresh App commit. Supersedes " +
        pull.head.sha + " so CI runs against the updated base.",
    );
  }
  private async comment(number: number, body: string) {
    try {
      await this.github.request("POST", "/issues/" + number + "/comments", {
        body,
      });
    } catch { /* The owning mutation already completed. */ }
  }
}
function encode(content: string) {
  const bytes = new TextEncoder().encode(content);
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary);
}
