<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Firewall\FirewallMutationInput;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Spike\DurableFirewall\FirewallAllowExecutorWorkflow;
use App\Spike\DurableFirewall\FirewallAllowJob;
use App\Spike\DurableFirewall\FirewallAllowWorkflow;
use App\Spike\DurableFirewall\FirewallEnsureRuleJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Workflow\V2\WorkflowStub;

/** SPIKE: start firewall:allow as a Durable Workflow instead of a Swamp run, and time it. */
final class SpikeFirewallAllowCommand extends Command
{
    protected $signature = 'spike:firewall-allow {node} {port} {--protocol=tcp} {--from=any} {--label=dw-spike} {--slow=0} {--no-wait} {--via=ansible : ansible (SSH from the Gateway), executor (Durable Workflow + primitives), job (plain Horizon job + primitives) or rule (one desired-state order)}';

    protected $description = 'SPIKE: run firewall:allow through Durable Workflow on Horizon';

    public function handle(FirewallMutationInput $input): int
    {
        $started = microtime(true);
        $node = Node::query()->findOrFail((int) $this->argument('node'));
        $rule = FirewallRule::query()->firstOrNew([
            'node_id' => $node->id, 'port' => (string) $this->argument('port'),
            'protocol' => $this->option('protocol'), 'source' => $this->option('from'),
        ]);
        $rule->fill(['action' => 'allow', 'label' => $this->option('label'), 'status' => LifecycleStatus::Provisioning,
            'failed_step' => null, 'error_code' => null])->save();
        $rule->setRelation('node', $node);

        if (in_array($this->option('via'), ['job', 'rule'], true)) {
            $key = 'spike-job-'.Str::lower((string) Str::ulid());
            $job = $this->option('via') === 'rule' ? FirewallEnsureRuleJob::class : FirewallAllowJob::class;
            $job::dispatch($key, $input->capture($rule), $node->id, (int) $this->option('slow'));
            $this->line("job {$key} dispatched in ".round(microtime(true) - $started, 2).' s');
            if ($this->option('no-wait')) {
                return self::SUCCESS;
            }
            $popped = Redis::connection('gateway')->command('blpop', [["spike:job:{$key}"], 180]);
            $output = is_array($popped) ? json_decode((string) $popped[1], true) : ['ok' => false, 'error_code' => 'spike.wait_timeout'];
            $rule->forceFill(['status' => ($output['ok'] ?? false) ? LifecycleStatus::Active : LifecycleStatus::Failed,
                'error_code' => $output['error_code'] ?? null, 'failed_step' => $output['failed_step'] ?? null])->save();
            $this->line('status '.(($output['ok'] ?? false) ? 'completed' : 'failed').' in '.round(microtime(true) - $started, 2).' s: '.json_encode($output));

            return ($output['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $executor = $this->option('via') === 'executor';
        $stub = WorkflowStub::make($executor ? FirewallAllowExecutorWorkflow::class : FirewallAllowWorkflow::class,
            'spike-fw-'.Str::lower((string) Str::ulid()));
        $executor
            ? $stub->start($input->capture($rule), $node->id, (int) $this->option('slow'))
            : $stub->start($input->capture($rule), (int) $this->option('slow'));
        $this->line("workflow {$stub->id()} run {$stub->runId()} started in ".round(microtime(true) - $started, 2).' s');
        if ($this->option('no-wait')) {
            return self::SUCCESS;
        }

        while ($stub->refresh()->running()) {
            usleep(100_000);
        }
        $status = $stub->status();
        $output = $stub->output();
        $rule->forceFill(['status' => ($output['ok'] ?? false) ? LifecycleStatus::Active : LifecycleStatus::Failed,
            'error_code' => $output['error_code'] ?? null, 'failed_step' => $output['failed_step'] ?? null])->save();
        $this->line("status $status in ".round(microtime(true) - $started, 2).' s: '.json_encode($output));

        return ($output['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
