<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use RuntimeException;

/** SPIKE: a refusal with a public error code, such as firewall.verify_failed. */
final class UfwRefusal extends RuntimeException {}
