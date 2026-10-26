<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Models\Node;
use Closure;

/**
 * Healthy answers for the agent commands that project a Route onto its Nodes: development leaf
 * certificates, the workload TLS check, and Caddy publication. One runner records them in call order,
 * so a test can assert which Node got which command and when. Rust tests prove what each command does.
 */
final class RouteProjectionAgentAnswers
{
    public const string CERTIFICATE_REQUEST = "CERTIFICATE REQUEST\n";

    /**
     * @param  (Closure(string, array<string, mixed>, Node): bool)|null  $refuse  refuses the matching call with the command's own failure code
     */
    public static function register(FakeAgentCommandRunner $agents, ?Closure $refuse = null): FakeAgentCommandRunner
    {
        $answers = [
            'certificate.request-leaf' => ['state' => 'request', 'request' => self::CERTIFICATE_REQUEST],
            'certificate.publish-leaf' => [],
            'certificate.inspect-leaf' => ['present' => true],
            'certificate.remove-leaf' => [],
            'route.verify-certificate' => [],
            'caddy.publish' => ['result' => 'published'],
        ];

        foreach ($answers as $command => $output) {
            $agents->answer($command, static function (array $input, Node $node) use ($command, $output, $refuse): array {
                if ($refuse instanceof Closure && $refuse($command, $input, $node)) {
                    throw self::refusal($command);
                }

                return $output;
            });
        }

        return $agents;
    }

    /** A real Node Caddy builder whose publication goes to the fake agent. */
    public static function builds(FakeAgentCommandRunner $agents): NodeCaddyBuilder
    {
        return new NodeCaddyBuilder(
            app(NodeCaddyfileRenderer::class),
            new NodeCaddyBuildLock(TestOrbitHome::scratch('caddy-build')),
            new NodeCaddyTransport($agents),
        );
    }

    /** @return list<array{node: string, content: string}> The Caddyfiles the agent published, in order. */
    public static function publications(FakeAgentCommandRunner $agents): array
    {
        return array_map(
            static fn (array $call): array => ['node' => $call['node'], 'content' => $call['input']['content']],
            self::calls($agents, 'caddy.publish'),
        );
    }

    /** @return list<array{node: string, scope: string}> The leaf scopes one certificate command acted on, in order. */
    public static function scopes(FakeAgentCommandRunner $agents, string $command): array
    {
        return array_map(
            static fn (array $call): array => ['node' => $call['node'], 'scope' => $call['input']['scope']],
            self::calls($agents, $command),
        );
    }

    /** @return list<array{node: string, command: string, input: array<string, mixed>}> */
    public static function calls(FakeAgentCommandRunner $agents, string $command): array
    {
        return array_values(array_filter($agents->calls, static fn (array $call): bool => $call['command'] === $command));
    }

    private static function refusal(string $command): AgentCommandRefused
    {
        return match ($command) {
            'route.verify-certificate' => new AgentCommandRefused($command, 'route.certificate_invalid', 'The workload does not serve a trusted certificate for the domain.', ['stderr' => 'injected failure']),
            'caddy.publish' => new AgentCommandRefused($command, 'caddy.publication_failed', 'The Caddy publication failed.', ['stage' => 'reload', 'message' => 'injected failure']),
            default => new AgentCommandRefused($command, 'certificate.leaf_failed', 'A certificate step failed on the Node.', ['step' => 'injected']),
        };
    }
}
