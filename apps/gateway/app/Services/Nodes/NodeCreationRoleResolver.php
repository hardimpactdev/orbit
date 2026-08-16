<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Enums\Nodes\NodeRoleName;
use App\Services\Nodes\Roles\NodeRoleRegistry;

final readonly class NodeCreationRoleResolver
{
    private const array TEMPLATES = [
        'operator',
        'app-development',
        'app-production',
        'gateway',
        'ingress',
        'database',
        's3',
        'metrics',
        'websocket',
        'analytics',
        'agent',
    ];

    private const array IMPLEMENTATION_PENDING_ROLES = [
        NodeRoleName::WebSocket->value,
    ];

    public function __construct(
        private NodeRoleRegistry $roleRegistry,
    ) {}

    public function resolve(?string $template, bool $operator, ?string $roles): NodeCreationRoleSelection
    {
        $template = $this->normalizeTemplate($template);
        $requestedRoles = $this->parseRoles($roles);

        if ($template !== null && $requestedRoles !== []) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: '--template and --roles cannot be used together.',
                meta: ['fields' => ['template', 'roles']],
            );
        }

        if ($operator && $requestedRoles !== []) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: '--operator and --roles cannot be used together.',
                meta: ['fields' => ['operator', 'roles']],
            );
        }

        if ($operator && $template !== null && $template !== 'operator') {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: '--operator can only be combined with --template=operator.',
                meta: ['fields' => ['operator', 'template']],
            );
        }

        if ($template !== null) {
            return $this->fromTemplate($template);
        }

        if ($operator) {
            return new NodeCreationRoleSelection(
                gateway: false,
                operator: true,
                clientIdentity: false,
                workloadRoles: [],
                template: null,
                requestedRoleMeta: 'operator',
            );
        }

        if ($requestedRoles === []) {
            return new NodeCreationRoleSelection(
                gateway: false,
                operator: false,
                clientIdentity: true,
                workloadRoles: [],
                template: null,
                requestedRoleMeta: 'client',
            );
        }

        return $this->fromExplicitRoles($requestedRoles);
    }

    /**
     * @return 'agent'|'analytics'|'app-development'|'app-production'|'database'|'gateway'|'ingress'|'metrics'|'operator'|'s3'|'websocket'|null
     */
    private function normalizeTemplate(?string $template): ?string
    {
        if ($template === null) {
            return null;
        }

        $template = trim($template);

        if ($template === '') {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: 'Node template is required when --template is supplied.',
                meta: ['field' => 'template'],
            );
        }

        if (! in_array($template, self::TEMPLATES, true)) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: 'Node template must be one of operator, app-development, app-production, gateway, ingress, database, s3, metrics, websocket, analytics, or agent.',
                meta: ['field' => 'template'],
            );
        }

        return $template;
    }

    /**
     * @return list<string>
     */
    private function parseRoles(?string $roles): array
    {
        if ($roles === null) {
            return [];
        }

        $parsed = array_values(array_filter(
            array_map(trim(...), explode(',', $roles)),
            static fn (string $role): bool => $role !== '',
        ));

        if ($parsed === []) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: 'At least one role is required when --roles is supplied.',
                meta: ['field' => 'roles'],
            );
        }

        return array_values(array_unique($parsed));
    }

    /**
     * @param  'agent'|'analytics'|'app-development'|'app-production'|'database'|'gateway'|'ingress'|'metrics'|'operator'|'s3'|'websocket'  $template
     */
    private function fromTemplate(string $template): NodeCreationRoleSelection
    {
        $selection = match ($template) {
            'operator' => new NodeCreationRoleSelection(false, true, false, [], $template, 'operator'),
            'gateway' => new NodeCreationRoleSelection(true, false, false, [], $template, 'gateway'),
            'app-development' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::AppDevelopment->value,
                    NodeRoleName::Database->value,
                ],
                $template,
                NodeRoleName::AppDevelopment->value,
            ),
            'app-production' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::AppProduction->value,
                ],
                $template,
                NodeRoleName::AppProduction->value,
            ),
            'ingress' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::Ingress->value,
                ],
                $template,
                NodeRoleName::Ingress->value,
            ),
            'database' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::Database->value,
                ],
                $template,
                NodeRoleName::Database->value,
            ),
            's3' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::S3->value,
                ],
                $template,
                NodeRoleName::S3->value,
            ),
            'metrics' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::Metrics->value,
                ],
                $template,
                NodeRoleName::Metrics->value,
            ),
            'websocket' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::WebSocket->value,
                ],
                $template,
                NodeRoleName::WebSocket->value,
            ),
            'agent' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::Agent->value,
                ],
                $template,
                NodeRoleName::Agent->value,
            ),
            'analytics' => new NodeCreationRoleSelection(
                false,
                false,
                false,
                [
                    NodeRoleName::Analytics->value,
                ],
                $template,
                NodeRoleName::Analytics->value,
            ),
        };

        $this->guardWorkloadRoleSelection($selection->workloadRoles);

        if (in_array($template, self::IMPLEMENTATION_PENDING_ROLES, true)) {
            throw new NodeCreationRoleInputException(
                errorCode: 'validation_failed',
                message: "Node template '{$template}' is not implemented yet.",
                meta: [
                    'field' => 'template',
                    'reason' => 'not_implemented',
                    'template' => $template,
                ],
            );
        }

        return $selection;
    }

    /**
     * @param  list<string>  $roles
     */
    private function fromExplicitRoles(array $roles): NodeCreationRoleSelection
    {
        $this->guardWorkloadRoleSelection($roles);

        foreach (self::IMPLEMENTATION_PENDING_ROLES as $pendingRole) {
            if (in_array($pendingRole, $roles, true)) {
                throw new NodeCreationRoleInputException(
                    errorCode: 'validation_failed',
                    message: "Node role '{$pendingRole}' is not implemented yet.",
                    meta: [
                        'field' => 'roles',
                        'reason' => 'not_implemented',
                        'role' => $pendingRole,
                    ],
                );
            }
        }

        return new NodeCreationRoleSelection(
            gateway: false,
            operator: false,
            clientIdentity: false,
            workloadRoles: $roles,
            template: null,
            requestedRoleMeta: $roles[0] ?? null,
        );
    }

    /** @param list<string> $roles */
    private function guardWorkloadRoleSelection(array $roles): void
    {
        foreach ($roles as $role) {
            if (! $this->roleRegistry->roleIsEligibleForWorkloadNodeCreation($role)) {
                throw NodeCreationRoleInputException::unsupportedWorkloadRole();
            }
        }

        $conflictingPair = $this->roleRegistry->firstConflictingRolePair($roles);

        if ($conflictingPair !== null) {
            throw NodeCreationRoleInputException::conflictingWorkloadRoles($conflictingPair);
        }
    }
}
