<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\NodeTld;
use App\Domain\Shared\ResourceOperationException;

/** Public admitted render values; never a model, transport identity or persisted Node.
 * @phpstan-import-type CertificatePlan from NodeRoleCertificateInput
 */
final readonly class NativeRouteNodeRender
{
    /** @param CertificatePlan $roleCertificates */
    public function __construct(public int $nodeId, public string $wireguardIp, public ?string $tld,
        public array $roleCertificates = ['leaves' => [], 'withdrawals' => []],
        public ?NodeEnrollmentCandidate $enrollment = null)
    {
        if (($enrollment !== null && ($enrollment->nodeId !== $nodeId || $enrollment->wireguardIp !== $wireguardIp || $enrollment->tld !== $tld))
            || $nodeId < 1 || filter_var($wireguardIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ($tld !== null && (! NodeTld::isValid($tld) || NodeTld::normalize($tld) !== $tld))) {
            throw new ResourceOperationException('route.node_render_invalid', 'The render requires the admitted Node identity and normalized address and TLD.', 422);
        }
    }
}
