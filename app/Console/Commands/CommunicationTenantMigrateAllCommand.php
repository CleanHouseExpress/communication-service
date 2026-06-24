<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\RunTenantMigrationsAction;
use App\Models\CommunicationTenant;
use Illuminate\Console\Command;

class CommunicationTenantMigrateAllCommand extends Command
{
    protected $signature = 'communication:tenant:migrate-all
        {--pretend : Validate and display targets without running migrations}
        {--force : Force execution in production}
        {--only= : Comma-separated orchestra tenant ids}
        {--status=active : Tenant status filter}';

    protected $description = 'Run communication tenant migrations for all matching tenants.';

    public function handle(RunTenantMigrationsAction $runTenantMigrations): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Use --force to run tenant migrations in production.');

            return self::FAILURE;
        }

        $tenants = $this->tenants();
        $summary = [
            'total' => $tenants->count(),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        if ($tenants->isEmpty()) {
            $this->summary($summary);

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->line("Tenant: {$tenant->orchestra_tenant_id}");

            $result = $runTenantMigrations->handle(
                tenant: $tenant,
                pretend: (bool) $this->option('pretend'),
                force: (bool) $this->option('force'),
            );

            if ($result->skipped) {
                $summary['skipped']++;
                $this->warn('  skipped: '.$result->error);

                continue;
            }

            if (! $result->success) {
                $summary['failed']++;
                $this->error('  failed');

                continue;
            }

            $summary['success']++;

            if ($result->pretend) {
                $this->info("  pretend: {$result->connectionName} {$result->migrationPath}");
            } else {
                $this->info('  migrated');
            }
        }

        $this->summary($summary);

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function tenants()
    {
        $query = CommunicationTenant::query()
            ->where('status', (string) $this->option('status'))
            ->orderBy('orchestra_tenant_id');

        $only = $this->only();

        if ($only !== []) {
            $query->whereIn('orchestra_tenant_id', $only);
        }

        return $query->get();
    }

    private function only(): array
    {
        $only = $this->option('only');

        if (! is_string($only) || trim($only) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $value): string => trim($value),
            explode(',', $only),
        )));
    }

    private function summary(array $summary): void
    {
        $this->line('Summary:');
        $this->line("  total: {$summary['total']}");
        $this->line("  success: {$summary['success']}");
        $this->line("  failed: {$summary['failed']}");
        $this->line("  skipped: {$summary['skipped']}");
    }
}
