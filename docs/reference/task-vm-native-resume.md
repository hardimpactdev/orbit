---
title: "Native Task VM resume"
description: "Resume a parked VM with one native parent."
---

# Native Task VM resume

The Gateway records the parked VM and its exact host and guest identities, including the guest's recorded host key pin. Swamp starts the provider, waits for the guest agent, and checks the agent and Pi in the same root. The Gateway reads the run's step statuses; no result step reports back. No child Operation or polled executor participates.
