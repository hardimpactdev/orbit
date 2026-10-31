<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Safe admitted GitHub intent and bounded native outcome; no external effects. */
final readonly class RecipePrProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha', 'author', 'required_check', 'deployed_recipe']));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = ValidatedData::object($data['result'] ?? []);
        if ($status === OperationStatus::Succeeded) {
            $url = $result['url'] ?? null;
            if ($code !== null || ($result['completed'] ?? null) !== true || ! in_array($result['outcome'] ?? null, ['checked', 'absent'], true)
                || ($url !== null && (! is_string($url) || preg_match('~\Ahttps://github\.com/hardimpactdev/orbit/pull/[1-9][0-9]*\z~D', $url) !== 1))) {
                $this->invalid();
            }
            $result = ['completed' => true, 'outcome' => $result['outcome'], ...($url === null ? [] : ['url' => $url])];
        } elseif ($status === OperationStatus::Failed) {
            if (! in_array($code, ['fleet.recipe_pr_stale', 'fleet.recipe_pending_deployment', 'fleet.recipe_pr_policy_refused'], true) || ($result['error_code'] ?? null) !== $code) {
                $this->invalid();
            }
            $result = ['error_code' => $code];
        } else {
            if ($code !== null) {
                $this->invalid();
            }
            $result = [];
        }

        return app(DirectOperation::class)->project($operation, $data, $result, 'recipe-pr');
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'The recipe PR result must be a bounded outcome for the fixed repository.', 422);
    }
}
