<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Shared\ResourceOperationException;

/** A Vaultwarden setup or sync failure. Its message never contains a secret. */
final class VaultException extends ResourceOperationException {}
