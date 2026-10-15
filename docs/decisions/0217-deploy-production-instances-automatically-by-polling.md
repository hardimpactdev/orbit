---
title: "ADR 0217: Deploy production Instances automatically by polling"
sidebarTitle: "0217 Automatic production deployments"
description: "In progress. A production Instance can deploy each new commit of its branch that passed the Project's merge check. The Gateway finds it by polling, not by a webhook, and deploys it as a pinned operation on the one operations worker."
---

# ADR 0217: Deploy production Instances automatically by polling

A production Instance can opt in to automatic deployment. Each minute, the Gateway reads the head of the Instance's deployment branch. When a newer commit passed the Project's `merge_check`, the Gateway deploys exactly that commit through the normal `instance.deploy` operation. The Gateway polls; it receives no webhook. Development defaults use the same tick.

## Status

In progress.

Principle: [One way, one name](/mission#principles). One tick, one operation kind, and one green-commit rule serve development defaults, production Instances, and, for the rule, automatic Gateway releases. No principle exception is proposed.

## Context

- Development defaults deployed from a scheduled command that ran the deploy inline, outside operations. [ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) planned to move it onto operations.
- A production deployment checked out the branch head at the moment it ran. When the head moved after a gate passed commit X, the deployment checked out the newer, unchecked head Y.
- `GreenCommitResolver` already finds the newest first-parent, green, descending commit that has not failed, and automatic Gateway releases use it with `Required checks`.
- The Gateway answers only WireGuard peers, apart from its bootstrap trust endpoints. The GitHub App has no push webhook.
- Hauzer and Mealou on `app-prod` are the first users, once their CI job `Required checks` is merged.

## Decision

The Gateway owns the tick, `orbit:auto-deploy`, every minute on the Gateway queue.

1. **Poll.** The tick reads the branch head with `git ls-remote` on the Gateway, with the Project's read credential. It caches the App installation and read tokens for 50 minutes, encrypted. An unchanged head starts no operation and writes no history row.
2. **Pin.** `instance.deploy` takes an optional `commit`. Release preparation proves with `git merge-base --is-ancestor` that the branch contains it, checks it out, and refuses otherwise with `deployment.commit_not_on_branch`. A failed deployment records the commit too. A manual deployment takes no commit and deploys the head, as before.
3. **Gate.** For a production Instance with `auto_deploy`, the target is the green-commit walk's answer from the deployed commit, with the blocked commits excluded. The same walk reports the head's own check, so the tick reads no second check. A failed head check is read again after 5 minutes, so a re-run that passes deploys. Without a `merge_check`, the target is the head. A `merge_check` needs the GitHub App, so a `gh_cli` Project with one is refused.
4. **Failures.** A failure before any Project step ran retries after 10, then 20 minutes, and blocks the commit after three attempts. Any other production failure blocks the commit at once. A blocked commit waits for a new commit or a manual deployment. Development defaults retry every failure under the same cap.
5. **Pause.** A manual deployment or a rollback pauses the Instance until `auto_deploy` is set again. A new commit does not resume it. The commit a rollback moved away from stays blocked. A queued automatic deployment checks for a pause again under the Instance lock that a rollback takes, so a rollback that started after the tick queued it always wins.
6. **Visibility.** History rows, Activity entries when a notable result starts, `instance:show`, and the release alerts `instance_deploy_failed`, `instance_deploy_stalled`, and `instance_deploy_paused`, each once per commit.

This changes [ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) step 4: the scheduled development deploy is now an operation.

## Rejected alternatives

- **A GitHub push or check-suite webhook.** It needs a public endpoint, a webhook secret, and replay protection on a Gateway that otherwise answers only WireGuard peers. Polling reuses existing paths, and its cost is one `ls-remote` a minute and a few API reads while a check runs. The price is up to a minute of delay.
- **Deploy the head after the gate passes.** The head can move between the check and the checkout, so the deployed commit would not be the checked one.
- **A deadline for pending checks.** The resolver skips a pending commit and walks back to the newest green descendant, so nothing waits on a deadline. A stall alert after 30 minutes reports a commit that does not deploy.
- **ETag requests.** Unchanged ticks already make no API call; the cache keeps the App's token requests rare.
- **Resume after a rollback when a new commit arrives.** A merge is not a decision to undo a rollback. Only setting `auto_deploy` again resumes.
- **A second worker for automatic deployments.** It would let two deployments run at once and need its own lock and recovery.

## Consequences

- A green commit deploys within about two minutes after its check passes, and the deployed commit is exactly the checked one.
- Development defaults need the Gateway queue, as manual deployments already do.
- Automatic and manual deployments share the one `operations` worker. A deployment can wait for another, and Instances behind one Route deploy one after another.
- A release alert files a problem in the outer loop, so a failing application deployment becomes a Backlog task in the `orbit` Project.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: [0209](/decisions/0209-run-long-operations-as-durable-queued-operations)
- Detail: [Deployments: Automatic deployments](/reference/deployments#automatic-deployments) and its "Why it works this way" sections
- Verify: `php artisan test --compact --filter='DeployInstance|ProductionDeployment|DeployDevelopmentDefaults|AutoDeploy|InstanceUpdate'` in apps/gateway
