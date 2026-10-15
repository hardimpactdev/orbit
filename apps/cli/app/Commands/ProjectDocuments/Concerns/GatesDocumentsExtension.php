<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments\Concerns;

use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\GatewayConnectorFactory;
use App\Support\ExtensionCommandVisibility;
use Orbit\Sdk\GatewayConnector;

/** The `project:document*` commands belong to the documents extension and follow its Gateway switch. */
trait GatesDocumentsExtension
{
    public function isHidden(): bool
    {
        return ExtensionCommandVisibility::listing()
            && app(GatewayExtensionState::class)->isEnabled('documents') !== true;
    }

    public function extensionSlug(): ?string
    {
        return 'documents';
    }

    protected function gatewayConnector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): ?GatewayConnector {
        $state = app(GatewayExtensionState::class)->discover();

        if (! $state->isKnown()) {
            $this->failUnknownExtensionState('documents', $state);

            return null;
        }

        return parent::gatewayConnector($repository, $connectors);
    }
}
