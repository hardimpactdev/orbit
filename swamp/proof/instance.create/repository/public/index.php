<?php

declare(strict_types=1);

header('Content-Type: application/json');
echo json_encode(['marker' => 'orbit-create-web-proof', 'checkout' => dirname(__DIR__)], JSON_THROW_ON_ERROR);
