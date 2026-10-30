<?php

declare(strict_types=1);

$publication = [
    'workflow' => 'operation-route-publication',
    'writer_config' => 'swamp.route_publication_writers',
    'writer_state' => 'route_publication_writer',
    'token_config' => 'swamp.run_token',
];

return [
    'route.caddy_converge' => $publication,
    'route.private_dns_publish' => $publication,
];
