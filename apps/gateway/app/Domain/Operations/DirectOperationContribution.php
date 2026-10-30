<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** Fixed direct-start declarations contributed by an installed extension. */
interface DirectOperationContribution
{
    /** @return array<string, array{workflow: string, writer_config: string, writer_state: string, projection: class-string<DirectOperationProjection>, private_material?: array<string,string>, secret_outputs?: array<string,string>, result_hook?: class-string<DirectOperationResultHook>}> */
    public function definitions(): array;
}
