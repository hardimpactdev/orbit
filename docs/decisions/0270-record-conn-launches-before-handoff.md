---
title: "Durable Conn launch handoff"
sidebarTitle: "0270 Durable Conn launches"
description: "Record Conn launch intent and progress in the Gateway, and let Swamp hand off the frozen first message to the admitted stock T3 server."
---

# ADR 0270: Record Conn launches before handoff

The Gateway owns launch identity, progress and the cancellation decision. Swamp reaches the admitted Instance and sends the first message to stock T3.

## Status

In progress.

Principle: [One way, one name](/mission#principles). Reuse native admission, reconciliation, teardown and the stock T3 launch command rather than adding a second machine runner or thread store.

## Context

An installed Conn device asks Orbit for a clone, then starts the first turn itself. It can lose either reply or disconnect before finishing. T3 `0.1.7` can commit the message and schedule the agent before returning its WebSocket response, so a lost response tells Orbit neither that the message is absent nor that cancellation is still safe.

## Decision

Record an encrypted private request and a caller-scoped admission before returning `202`. The Gateway advances the parent from keyed native child records. Cancel and dispatch commit under the same parent lock. Swamp sends the frozen payload only after dispatch wins, to the pinned T3 incarnation. After an ambiguous reply, exact T3 message evidence proves acceptance; sending again requires both authoritative absence and a known safe replay window. Publication and application readiness are separate records of progress, not parts of the T3 acknowledgement.

## Rejected alternatives

- **Run the launch from the Conn device.** Disconnects leave clone, thread and profile as separate, unpaired writes.
- **Make a Gateway queue worker perform machine work.** Existing native child admission and reconciliation already own machine work and its recovery.
- **Treat a missing reply or a reused URL as proof.** Neither identifies the original state or establishes whether the message is durable.
- **Allow Cancel until the client sees success.** T3 can already have started the turn. Launch Cancel cannot take that turn back.

## Consequences

- A second device with current profile and target access can follow progress or control the launch without becoming its initiator.
- An ambiguous launch can need recovery without an available resend. An unverified destroy remains open instead of declaring a clone gone.
- Application completion can fail without losing the accepted thread.

## Affects

- Components: apps/gateway
- ADRs: none.
- Detail: [Durable launches](/reference/conn#durable-launches-v1), [record coordination](/reference/operations#record-coordination-and-native-children), and [Conn launch transport](/reference/development-containers#conn-launch-transport).
- Verify: stock T3 lost-response baseline, focused Gateway and Swamp tests, and the task copy's nested-fleet proof before publication.
