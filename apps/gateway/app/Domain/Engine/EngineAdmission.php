<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Models\Operation;

/**
 * A kind whose admission reads more than the Operation's intent, such as the Node's features or the live records the intent
 * names. Routing asks it once, before any step is bound (EngineKinds::routes); a refusal throws `EngineUnsupported` and the whole
 * Operation runs on Swamp. Once bound, the kind's steps and orders come from the pinned intent alone, so no later read can stop
 * a started run, its resends or its cleanup.
 */
interface EngineAdmission
{
    public function admit(Operation $operation): void;
}
