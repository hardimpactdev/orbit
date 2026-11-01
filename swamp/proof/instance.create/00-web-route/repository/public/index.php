<?php

declare(strict_types=1);

header('Content-Type: application/json');
echo json_encode(['marker' => 'orbit-create-web-proof', 'checkout' => dirname(__DIR__), 'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION], JSON_THROW_ON_ERROR);
