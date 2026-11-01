import { z } from "npm:zod@4.4.3";
import { RecipeGitHub } from "./api.ts";
import {
  artifactFingerprint,
  isDeployed,
  parseRecipe,
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
export type RecipePlan = { action: string; [key: string]: unknown };
type Indexes = {
  snapshot: typeof bumpSnapshot;
  artifacts: typeof artifactIndexes;
};
const checked = { action: "none", outcome: "checked" } as const;
const absent = { action: "none", outcome: "absent" } as const;
const snapshotBody =
  "Advances the authenticated Ubuntu snapshot and Ubuntu pins. Swamp checks App authorship, the signed single commit, exact recipe-only change and required checks before merge. Image builds gate fleet convergence.";
const artifactBody =
  "Updates complete Sury PHP and Incus LTS sets from authenticated indexes. Swamp verifies the App commit, recipe-only change, pin-derived branch, authenticated candidate and required checks before merge. Image builds gate fleet convergence; Incus changes require explicit convergence on a drained host.";

/** One admitted GitHub action. Native workflow recovery owns uncertain HTTP writes. */
export class RecipePrPlanner {
  constructor(
    private github: RecipeGitHub,
    private indexes: Indexes = {
      snapshot: bumpSnapshot,
      artifacts: artifactIndexes,
    },
  ) {}
  async prepare(raw: unknown): Promise<RecipePlan> {
    const input = RecipeIntent.parse(raw);
    if (input.date !== currentDate()) {
      throw new Error("fleet.recipe_pr_stale");
    }
    if (input.action === "open") return await this.open(input);
    const pulls = z.array(Listed).parse(
      await this.github.list(
        "/pulls?state=open&sort=created&direction=asc",
      ),
    );
    const pull = pulls.find((pull) =>
      pull.number === input.pull_number &&
      pull.head.sha === input.head_sha
    );
    if (!pull) return absent;
    const main = (await this.github.content("main")).content;
    if (input.action === "retire") {
      return await this.retire(input, pull, pulls, main) ?? checked;
    }
    if (input.action === "update") {
      return await this.refresh(input, pull);
    }
    const retirement = await this.retire(input, pull, pulls, main);
    if (retirement) return retirement;
    return await this.merge(input, pull);
  }
  private async open(input: Input) {
    const head = Sha.parse(
      z.object({ object: z.object({ sha: Sha }) }).parse(
        await this.github.get("/git/ref/heads/main"),
      ).object.sha,
    );
    const main = await this.github.content(head);
    if (!isDeployed(main.content, input.deployed_recipe)) {
      throw new Error("fleet.recipe_pending_deployment");
    }
    const recipe = parseRecipe(main.content),
      snapshot = input.date + "T000000Z";
    if (
      input.variant === "snapshot" && recipe.ubuntu.snapshot >= snapshot
    ) {
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
    const plan = {
      main: head,
      base_blob: main.sha,
      content: updated,
      branch,
      title: input.variant === "snapshot"
        ? "Bump machine recipe snapshot to " + snapshot
        : "Bump the recipe artifacts (Sury PHP and Incus LTS)",
    };
    const existing = z.array(Listed).parse(
      await this.github.list(
        "/pulls?state=all&head=" +
          encodeURIComponent("hardimpactdev:" + branch),
      ),
    );
    if (existing.length) return { ...checked, url: existing[0].html_url };
    return {
      action: "open",
      ...plan,
      body: input.variant === "snapshot" ? snapshotBody : artifactBody,
    };
  }
  private async retire(
    input: Input,
    pull: ListedPull,
    pulls: ListedPull[],
    main: string,
  ) {
    const date = nightlySnapshot(pull.head.ref);
    if (!date || pull.user.login !== input.author) return null;
    let reason = parseRecipe(main).ubuntu.snapshot >= date
      ? "main's Ubuntu snapshot has reached this PR's date."
      : null;
    if (!reason) {
      for (const newer of pulls) {
        const next = nightlySnapshot(newer.head.ref);
        if (
          !next || next <= date || newer.user.login !== input.author
        ) {
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
          reason = "a newer App nightly PR is open: " +
            newer.html_url;
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
    ) return null;
    return {
      action: "close",
      number: pull.number,
      head: pull.head.sha,
      branch: pull.head.ref,
      author: input.author,
      comment: "Superseded this nightly recipe PR because " + reason,
    };
  }
  private async merge(input: Input, pull: ListedPull) {
    if (!Branch.safeParse(pull.head.ref).success) return checked;
    const artifact = pull.head.ref.startsWith("nightly/artifacts-");
    const checks = await this.github.checks(
      pull.head.sha,
      input.required_check,
    );
    const green = checkState(checks, pull.head.sha, input.required_check) ===
      "passed";
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
        return {
          action: "close",
          number: pull.number,
          head: pull.head.sha,
          branch: pull.head.ref,
          author: input.author,
        };
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
    return {
      action: "merge",
      number: pull.number,
      head: pull.head.sha,
      branch: pull.head.ref,
      author: input.author,
    };
  }
  private async refresh(input: Input, pull: ListedPull) {
    const snapshot = nightlySnapshot(pull.head.ref);
    if (
      snapshot !== input.date + "T000000Z" ||
      pull.user.login !== input.author
    ) return checked;
    const path = "/pulls/" + pull.number,
      original = await this.github.get(path);
    const pr = appPull(
      original,
      pull.head.sha,
      pull.head.ref,
      input.author,
    );
    if (!pr || pr.draft || pr.commits !== 1 || pr.changed_files !== 1) {
      return checked;
    }
    const checks = await this.github.checks(
      pull.head.sha,
      input.required_check,
    );
    if (!trustedFailedChecks(checks, pull.head.sha, input.required_check)) {
      return checked;
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
    ) return checked;
    const files = z.array(
      z.object({ filename: z.string(), status: z.string() }),
    ).parse(await this.github.get(path + "/files?per_page=2"));
    if (
      files.length !== 1 || files[0].filename !== RecipePath ||
      files[0].status !== "modified"
    ) return checked;
    const before = (await this.github.content(parent)).content,
      after = (await this.github.content(pull.head.sha)).content;
    if (
      parseRecipe(before).ubuntu.snapshot >= snapshot! ||
      await this.indexes.snapshot(before, snapshot!) !== after
    ) return checked;
    if (
      !untouched(
        await this.github.get(
          "/issues/" + pull.number + "/timeline?per_page=100",
        ),
        input.author,
        pull.head.sha,
      )
    ) return checked;
    const history = await this.github.request("POST", "/graphql", {
      query:
        "query($owner: String!, $name: String!, $number: Int!) { repository(owner: $owner, name: $name) { id pullRequest(number: $number) { userContentEdits(first: 100) { nodes { editor { login } } pageInfo { hasNextPage } } } } }",
      variables: {
        owner: "hardimpactdev",
        name: "orbit",
        number: pull.number,
      },
    });
    const graph = z.object({
      errors: z.unknown().optional(),
      data: z.object({
        repository: z.object({
          id: z.string(),
          pullRequest: z.object({
            userContentEdits: z.object({
              nodes: z.array(
                z.object({
                  editor: z.object({ login: z.string() }),
                }),
              ),
              pageInfo: z.object({ hasNextPage: z.boolean() }),
            }),
          }),
        }),
      }),
    }).parse(history.data);
    if (
      graph.errors !== undefined ||
      graph.data.repository.pullRequest.userContentEdits.pageInfo
        .hasNextPage ||
      graph.data.repository.pullRequest.userContentEdits.nodes.some((
        edit,
      ) => edit.editor.login !== input.author)
    ) return checked;
    const content = (await this.github.content(main)).content;
    const updated = await this.indexes.snapshot(content, snapshot!);
    parseRecipe(updated);
    const baseTree = z.object({ tree: z.object({ sha: Sha }) }).parse(
      await this.github.get("/git/commits/" + main),
    ).tree.sha;
    return {
      action: "update",
      number: pull.number,
      branch: pull.head.ref,
      head: pull.head.sha,
      author: input.author,
      original,
      main,
      base_tree: baseTree,
      content: updated,
      repository_id: graph.data.repository.id,
      title: "Bump machine recipe snapshot to " + snapshot,
      comment:
        "Recreated the failed nightly snapshot on current main as one fresh App commit. Supersedes " +
        pull.head.sha + " so CI runs against the updated base.",
    };
  }
}
