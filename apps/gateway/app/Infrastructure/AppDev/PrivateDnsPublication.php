<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * One private DNS publication: the complete state as the phase on the vpn Node takes it, with its revision.
 * The revision is taken under the revision lock in the transaction that reads the rows. A change stores its
 * rows before it asks for a render, so a later revision holds every change stored before it.
 */
final readonly class PrivateDnsPublication
{
    public function __construct(
        private DevelopmentDnsConfigRenderer $renderer,
        private PrivateDnsRevision $revisions,
    ) {}

    /** @return array{revision: string, listen: list<string>, tlds: list<array{name: string, address: string}>, records: list<array{name: string, address: string, pair: bool}>} */
    public function render(): array
    {
        return DB::transaction(fn (): array => [
            'revision' => $this->revisions->next(),
            ...$this->renderer->state()->toIntent(),
        ]);
    }

    /**
     * What a parent workflow or the dns.publish operation carries for one vpn Node.
     *
     * @return array<string, mixed>
     */
    public function entry(Node $node): array
    {
        return ['node_id' => $node->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($node), 'dns' => $this->render()];
    }

    /**
     * The same render with a newer revision. A restore that runs after its forward publication takes one, so the Node installs it.
     *
     * @param  array<string, mixed>  $dns
     * @return array<string, mixed>
     */
    public function restamp(array $dns): array
    {
        return [...$dns, 'revision' => $this->revisions->next()];
    }

    /**
     * Whether two renders publish the same DNS. The revision does not count.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    public static function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        unset($a['revision'], $b['revision']);

        return $a == $b;
    }
}
