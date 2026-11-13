---
title: "ADR 0256: A clone create is two Operations"
sidebarTitle: "0256 Clone create in two"
description: "In progress. A container clone's create ends when its thread is usable; a second Operation, instance.complete, finishes the rest. Only that second Operation makes the clone active."
---

# ADR 0256: A clone create is two Operations

A container clone's `instance.create` ends when its workspace is checked out and its T3 server is registered: the user can start a thread. A second Operation of its own kind, `instance.complete`, then creates the database, starts a nested fleet, starts the Processes, publishes the Route and checks the clone. The clone is fully set up when its `status` is `active`, and only a succeeded `instance.complete` makes it so.

## Status

In progress.

Decided on 2026-11-13 by the owner of the create experience, with amendments the same day. The kind, its command and its workflow come first, while the create still does everything. The change that makes the create hand over to the completion completes this decision.

Principle: [One way, one name](/mission#principles). One field, `active`, means fully set up for every reader, and one action admits the completion for every caller. The user gets a usable thread about 10 seconds sooner; the clone needs about 10 seconds more to be fully set up than with one Operation.

## Context

Today one `instance.create` run does every step of a clone, and the thread opens only when the last one ends, after 31 to 34 seconds on the fixture Projects. The user needs only the checkout, `.env` and T3 to start work. The Processes, the database fill, a nested fleet and the Route can follow.

One Operation has one run, one budget and one terminal projection ([How an operation runs](/reference/operations#how-an-operation-runs)). A second terminal path in the create's projection would mean two ends for one record.

## Decision

- **Two Operations.** `instance.create` ends at the registration. Its terminal projection admits `instance.complete` for the same Instance in the same transaction, with the Instance lock, so nothing takes the Instance in between.
- **One field means fully set up.** `Instance.status == active`. The create leaves the clone `source_resolved`; the completion's success makes the Route and then the Instance `active`. Every reader of `active` (deploy, migrate, sleep, renewal, task copies) keeps its meaning.
- **One admission.** `instance:complete`, the API and the create's projection call the same action. It admits a container clone that is `source_resolved`, has its starting commit and a registered T3 server. Anything else refuses with `instance.complete_unavailable`.
- **Its own input and folder.** The completion reads the create's admitted database plan and Processes, captures the Route publication again, and stages its own private folder. It never reads the create's folder, which holds T3 session tokens.
- **Failure keeps the thread.** A failed or cancelled completion withdraws a Route that it published and keeps the clone `source_resolved`, the container running and T3 registered. Its recovery names `instance:complete` as the retry.
- **The Route hold moves.** `instance.complete` holds the Route publication Nodes for its whole run. `instance.create` leaves the hold when it no longer publishes a Route.

## Rejected alternatives

- A child workflow of the create: measured 8 seconds slower on a plain clone, because the child must wait for the checkout, while the Route and database steps start beside the container today.
- A ready mark inside one run: saves the same seconds for the user, but gives one Operation two meanings of "done" and needs a mid-run Gateway endpoint.
- `instance:setup`: it runs Project setup steps on a checkout, not a clone's services.

## Consequences

- The thread opens about 10 seconds sooner; "fully set up" needs about 10 seconds more (two run starts, two stage steps, two finishes). Task copies and release health creates wait about 10 seconds longer.
- A late failure is visible only to a client that follows the completion.
- A destroy while the completion runs must cancel it first.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: none
- Detail: [Complete a clone](/reference/development-containers#complete-a-clone)
- Verify: `apps/gateway/tests/Feature/Domain/Instances/InstanceCompleteTest.php`, `swamp/tests/instance_complete_workflow_test.ts`
