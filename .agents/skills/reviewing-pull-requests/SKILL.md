---
name: reviewing-pull-requests
description: Use when independently reviewing an Orbit feature proposal or completed PR, or auditing test quality and unused code on request.
---

# Reviewing Pull Requests

Independently assess whether the feature's architecture, documentation, and behavior agree. Review the supplied proposal or PR and record the revision.

For a requested review before coding, check the intended behavior, feasibility, proposed ADRs, documentation, and important failure cases. Return findings for the author to address.

For a requested test cleanup or dead-code sweep, use the [optional audit procedure](references/test-audit.md). It adds evidence for safe removals and assertion repairs, not a mandatory PR gate. Use the project’s existing testing guidance for routine test review.

## Review a completed PR

Ad-hoc reviewers outside task copies, such as Codex agents an owner starts, apply the same [reviewer rule](../../../docs/reference/tasks.md#the-reviewer-rule) to decide what blocks.

Check correctness, regressions, test coverage, architectural decisions, and documentation. Inspect the required CI results. Check affected security boundaries, including ownership, untrusted input, credentials, TLS, and SSH identities.

For a change to the Rust executor or to an engine flow, apply [Primitives](../../../docs/reference/executor.md#primitives). List every action the diff adds or changes and check each against the five conditions. An action that holds an Orbit concept, builds an Orbit name or path, bundles several resources, or ports an Operation, a step or a Swamp phase misses the feature's architecture, so it blocks as a missed deliverable under the reviewer rule, also behind a flag that is off. The fix is a flow in the Gateway that composes catalogue primitives.

For command behavior, use [designing-cli-commands](../designing-cli-commands/SKILL.md) and the [CLI standard](../../../docs/reference/cli-ux.md). Use [verifying-cli-output](../verifying-cli-output/SKILL.md) for real terminal checks. For a web UI change, open the phone and desktop screenshots from [verifying-web-ui](../verifying-web-ui/SKILL.md) and judge the layout on a phone. Do not stop at the diff.

Review the author's pre-PR proof evidence from the [task copy and its nested fleet](../../../docs/reference/tasks.md#build-a-feature-in-one-copy). Use [verifying-commands](../verifying-commands/SKILL.md) to reproduce user-visible behavior and important failure cases there. Verify the running source and the exact cases and verdicts, not just a green summary. Task groups do not declare topologies; do not acquire a legacy topology for review.

Unavailable, failed, unrun or interrupted proof is not a pass. Record the missing evidence and apply the [reviewer rule](../../../docs/reference/tasks.md#the-reviewer-rule); do not infer coverage from CI, whose proof job is intentionally off for now. A required check on the running production Gateway is an operator step after deploy, not a replacement for pre-PR proof.

## Report

Give actionable findings with file references, their effect, and suggested corrections. Include the reviewed commit, check results, copy/nested-fleet proof evidence, and limitations. Link sanitized evidence stored outside the repository.

After fixes, review the changes and repeat affected checks. Confirm that the final assessment covers the current PR commit. Publish the review when authorized. The gate and the merge are stage 4 of the [command development pipeline](../../../docs/reference/command-development-pipeline.mdx#4-pr-and-ci).
