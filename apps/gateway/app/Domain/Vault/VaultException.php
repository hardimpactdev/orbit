<?php

declare(strict_types=1);

namespace App\Domain\Vault;

use App\Domain\Shared\ResourceOperationException;

/** A Vaultwarden setup or sync failure. Its message never contains a secret. */
final class VaultException extends ResourceOperationException {}
