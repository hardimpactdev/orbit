<?php

declare(strict_types=1);

require __DIR__.'/../../../apps/gateway/app/Support/ValidatedData.php';
require __DIR__.'/../../../apps/gateway/app/Domain/Routes/NativeRoutePublicationArtifacts.php';

$publications = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach ($publications as &$publication) {
    foreach (['before', 'prepared', 'withdrawn', 'after'] as $phase) {
        $publication[$phase]['php']['configurations'] = (object) $publication[$phase]['php']['configurations'];
    }
}
unset($publication);

echo json_encode((new App\Domain\Routes\NativeRoutePublicationArtifacts)->capture($publications), JSON_THROW_ON_ERROR);
