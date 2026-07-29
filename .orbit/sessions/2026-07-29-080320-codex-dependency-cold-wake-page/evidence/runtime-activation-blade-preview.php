<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);

require "{$root}/apps/gateway/vendor/autoload.php";

$app = require "{$root}/apps/gateway/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

$progress = max(0, min(100, (int) ($_GET['progress'] ?? 50)));

echo view('runtime-activation', [
    'name' => 'horizon-demo.nmbp',
    'steps' => [
        [
            'key' => 'dependency:composer',
            'label' => 'Installing PHP dependencies',
            'status' => 'done',
        ],
        [
            'key' => 'dependency:npm',
            'label' => 'Installing frontend dependencies',
            'status' => 'done',
        ],
        [
            'key' => 'process:horizon',
            'label' => 'Starting horizon',
            'status' => 'active',
        ],
        [
            'key' => 'process:vite',
            'label' => 'Starting vite',
            'status' => 'waiting',
        ],
    ],
    'completedSteps' => (int) floor($progress / 25),
    'totalSteps' => 4,
    'progress' => $progress,
    'nonce' => 'preview-nonce',
    'failed' => isset($_GET['failed']),
    'refreshUri' => '/runtime-activation-blade-preview.php',
    'retryUri' => '/runtime-activation-blade-preview.php?retry=1',
])->render();
