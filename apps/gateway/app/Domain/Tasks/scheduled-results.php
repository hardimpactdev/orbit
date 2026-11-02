<?php

declare(strict_types=1);

use App\Domain\Tasks\ProofReaper;

return [ProofReaper::Workflow => ['workflow' => 'proof-reaper', 'publishes_inputs' => true,
    'terminal_result' => ['job' => 'reap', 'step' => 'decide', 'native' => true, 'captures' => [
        'revision' => ['job' => 'reap', 'step' => 'decide', 'field' => 'revision'],
        'cancel' => ['job' => 'reap', 'step' => 'decide', 'field' => 'cancel'],
    ]], 'projection' => ProofReaper::class]];
