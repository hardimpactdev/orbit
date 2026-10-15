<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| The Gateway broadcasts every record change on a single private channel,
| `orbit`. Any authenticated Gateway API caller (an active WireGuard peer
| Node, the same identity `RequireActiveWireGuardPeer` resolves for the
| rest of the API) may subscribe; there is no per-node scoping. The progress
| of one operation also goes out on `private-operation.{id}`, which the same
| callers may subscribe to while the operation exists.
|
*/

Broadcast::channel('orbit', fn (Node $node): bool => true);

Broadcast::channel('operation.{operation}', fn (Node $node, int|string $operation): bool => Operation::query()->whereKey((int) $operation)->exists());
