<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationResultProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Orbit\Extensions\Conn\Models\ConnPairing;

/** Records public native HTTP receipts from the one terminal history read. */
final readonly class ConnRevocationProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperationResultProjection $projection) {}

    public function input(Operation $operation): array
    {
        return $this->projection->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $sessions = $this->receipt($raw['session_receipt'] ?? null);
            $links = $this->receipt($raw['link_receipt'] ?? null);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $failure = ValidatedData::object($data['failure'] ?? []);
            $unmatched = ($sessions['error_code'] ?? $failure['detail'] ?? null) === 'conn.revocation_unmatched';
            $intent = ValidatedData::object($operation->input['intent']);
            if (! is_array($intent['pairings'])) {
                throw new LogicException('Conn revocation requires its admitted pairings.');
            }
            $delivered = array_filter($intent['pairings'], fn (mixed $pairing): bool => (ValidatedData::object($pairing)['delivered'] ?? false) === true);
            if ($status === OperationStatus::Succeeded && $delivered !== [] && ($sessions['revoked'] ?? 0) === 0) {
                $unmatched = true;
            }
            if ($unmatched) {
                $status = OperationStatus::Failed;
                $data['status'] = $status->value;
            }
            if ($status === OperationStatus::Succeeded) {
                $data['result'] = ['backend' => 'revoked', 'revoked_sessions' => $sessions['revoked'] ?? 0];
            } elseif (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)) {
                $data['error_code'] = $unmatched ? 'conn.revocation_unmatched' : 'conn.revocation_failed';
                $data['result'] = ['error_code' => $data['error_code']];
            } else {
                $data['result'] = [];
            }
            $projected = $this->projection->apply($operation, $data);
            if (! $terminal && ! $unmatched && $status->terminal() && $status !== OperationStatus::Succeeded && ($sessions['valid'] ?? false) === true) {
                $admittedLinks = ValidatedData::stringList($intent['links']);
                $completed = ValidatedData::integerList($links['completed'] ?? []);
                $ids = ValidatedData::integerList($operation->input['pairing_ids']);
                $pairingLinks = $operation->input['pairing_links'];
                if (! is_array($pairingLinks)) {
                    throw new LogicException('Conn revocation requires its admitted links.');
                }
                foreach ($pairingLinks as $index => $link) {
                    $linkIndex = array_search($link, $admittedLinks, true);
                    if ($link === null || ($linkIndex !== false && in_array($linkIndex, $completed, true))) {
                        ConnPairing::query()->whereKey($ids[$index])
                            ->where('conn_environment_id', $operation->input['conn_environment_id'])
                            ->where('node_id', $operation->target_id)->update(['revoked_at' => Carbon::now(), 'credential' => null]);
                    }
                }
            }

            return $projected;
        });
    }

    /** @return array<string,mixed> */
    private function receipt(mixed $value): array
    {
        return is_string($value) ? ValidatedData::object(json_decode($value, true, flags: JSON_THROW_ON_ERROR)) : [];
    }
}
