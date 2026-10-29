<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** Generated secret outputs are transient until Gateway extraction; never retain them in target receipts or activity. */
interface OperationSecretOutputTransport extends OperationTransport {}
