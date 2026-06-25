<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Jobs\DispatchAgentForMessageJob;
use App\Jobs\SendOutboundMessageJob;
use App\Models\CommunicationFailedJob;
use App\Support\Tenancy\CurrentTenantConnection;
use Illuminate\Console\Command;
use Throwable;

class CommunicationQueueRetryFailedCommand extends Command
{
    protected $signature = 'communication:queue:retry-failed
        {--conversation= : Filter by conversation id}
        {--job= : Filter by job class basename}
        {--tenant= : Filter by tenant id}
        {--list : List matching unresolved failures}
        {--retry : Reprocess matching unresolved failures}';

    protected $description = 'List or manually retry unresolved communication queue failures.';

    public function handle(
        ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        CurrentTenantConnection $currentTenantConnection,
    ): int {
        $hadTenantContext = $currentTenantConnection->connectionName() !== null;

        try {
            if ((bool) config('communication.tenancy.runtime.enabled', false)) {
                $tenantId = $this->option('tenant');

                if (! is_string($tenantId) || $tenantId === '') {
                    $this->error('Use --tenant when tenant runtime is enabled.');

                    return self::FAILURE;
                }

                $resolveTenantRuntimeConnection->handle($tenantId);
            }

            $failedJobs = $this->query()->get();
            $shouldRetry = (bool) $this->option('retry');
            $shouldList = (bool) $this->option('list') || ! $shouldRetry;

            if ($shouldList) {
                $this->renderList($failedJobs);
            }

            if (! $shouldRetry) {
                return self::SUCCESS;
            }

            $summary = ['success' => 0, 'failed' => 0, 'skipped' => 0];

            foreach ($failedJobs as $failedJob) {
                try {
                    $job = $this->restoreJob($failedJob);

                    if ($job === null) {
                        $summary['skipped']++;
                        $this->warn("Skipped {$failedJob->id}: unsupported or incomplete job payload.");

                        continue;
                    }

                    app()->call([$job, 'handle']);

                    $failedJob->forceFill([
                        'resolved_at' => now(),
                        'metadata' => [
                            ...($failedJob->metadata ?? []),
                            'resolution' => 'manual_retry_succeeded',
                        ],
                    ])->save();

                    $summary['success']++;
                    $this->info("Resolved {$failedJob->id}.");
                } catch (Throwable $exception) {
                    $failedJob->forceFill([
                        'exception_class' => $exception::class,
                        'attempts' => $failedJob->attempts + 1,
                        'failed_at' => now(),
                        'metadata' => [
                            ...($failedJob->metadata ?? []),
                            'message' => $this->safeMessage($exception->getMessage()),
                            'last_manual_retry_at' => now()->toIso8601String(),
                        ],
                    ])->save();

                    $summary['failed']++;
                    $this->error("Retry failed for {$failedJob->id}: {$this->safeMessage($exception->getMessage())}");
                }
            }

            $this->line('Summary:');
            $this->line("  success: {$summary['success']}");
            $this->line("  failed: {$summary['failed']}");
            $this->line("  skipped: {$summary['skipped']}");

            return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            if (! $hadTenantContext) {
                $currentTenantConnection->clear();
            }
        }
    }

    private function query()
    {
        $query = CommunicationFailedJob::query()
            ->whereNull('resolved_at')
            ->orderBy('failed_at');

        foreach ([
            'conversation' => 'conversation_id',
            'job' => 'job_name',
            'tenant' => 'tenant_id',
        ] as $option => $column) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    private function restoreJob(CommunicationFailedJob $failedJob): DispatchAgentForMessageJob|SendOutboundMessageJob|null
    {
        $payload = $failedJob->payload ?? [];
        $tenantId = isset($payload['tenant_id']) ? (string) $payload['tenant_id'] : $failedJob->tenant_id;

        return match ($failedJob->job_name) {
            class_basename(DispatchAgentForMessageJob::class) => isset($payload['message_id'])
                ? new DispatchAgentForMessageJob((string) $payload['message_id'], $tenantId)
                : null,
            class_basename(SendOutboundMessageJob::class) => isset($payload['outbound_message_id'])
                ? new SendOutboundMessageJob((string) $payload['outbound_message_id'], $tenantId)
                : null,
            default => null,
        };
    }

    private function renderList($failedJobs): void
    {
        if ($failedJobs->isEmpty()) {
            $this->info('No unresolved communication queue failures found.');

            return;
        }

        $this->table(
            ['id', 'tenant', 'job', 'conversation', 'attempts', 'failed_at'],
            $failedJobs->map(fn (CommunicationFailedJob $failedJob): array => [
                $failedJob->id,
                $failedJob->tenant_id,
                $failedJob->job_name,
                $failedJob->conversation_id,
                $failedJob->attempts,
                $failedJob->failed_at?->toIso8601String(),
            ])->all(),
        );
    }

    private function safeMessage(string $message): string
    {
        $redacted = preg_replace(
            '/(token|authorization|client-token|password)([=: ]+)[^\s&]+/i',
            '$1$2[redacted]',
            $message,
        ) ?? $message;

        return mb_substr(trim(preg_replace('/\s+/', ' ', $redacted) ?? $redacted), 0, 300);
    }
}
