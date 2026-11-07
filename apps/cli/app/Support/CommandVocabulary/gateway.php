<?php

declare(strict_types=1);

/** Automatic releases use gateway:auto-release; individual releases use gateway:release. */
return [
    'actions' => ['deploy', 'resume', 'rollback', 'status', 'trust', 'use'],
    'noun_endings' => [],
    'route_exceptions' => [],
];
