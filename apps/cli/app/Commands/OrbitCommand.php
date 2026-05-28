<?php

declare(strict_types=1);

namespace App\Commands;

/**
 * Backward-compatible alias. New commands should extend GatewayCommand,
 * LocalOnlyCommand, or BootstrapGatewayCommand directly.
 *
 * @deprecated Extend GatewayCommand instead.
 */
abstract class OrbitCommand extends GatewayCommand {}
