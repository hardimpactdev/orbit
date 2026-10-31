---
title: "Instance transfer"
description: "How the Gateway moves one development Instance to another app-dev Node, keeps its ID, and recovers from a failed or interrupted move."
covers:
  - apps/gateway/app/Actions/Instances/StartInstanceTransferAction.php
  - apps/gateway/app/Domain/Instances/Transfer/**
  - apps/gateway/app/Domain/Instances/{TransferCompensation,TransferLifecycleExecution,InstanceTransferState}.php
  - apps/gateway/app/Http/Controllers/Api/InstanceTransfersController.php
  - apps/gateway/{app/Models/InstanceTransfer.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/cli/app/Commands/Instances/TransferInstanceCommand.php
  - packages/php-sdk/src/Requests/Instances/TransferInstanceRequest.php
---

# Instance transfer

Instance transfer is unavailable while its native multi-Node workflow is being built. The API returns `operation.kind_unavailable` before reserving a destination, stopping runtime, changing Routes or creating an Operation. Orbit does not run a polled transfer or a Gateway child workflow.

The native parent must own checkout transfer, optional SQLite copy, environment synchronization, runtime cutover, Route publication and rollback as steps in one root. Each Node effect must run on that exact Node worker. The shared private API still permits only the exact root Node to read its material. A source Node cannot read material owned by the destination. Transfer does not relay archives or credentials through the Gateway and does not use Node 209 as a foreign-Node fallback.

A complete native archive-transfer contract and fresh Route publication composition are required before admission can return. A recorded transfer keeps its stored state; an unavailable new request does not claim rollback or successful cleanup.
