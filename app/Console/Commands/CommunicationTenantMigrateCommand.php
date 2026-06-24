<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\RunTenantMigrationsAction;
use App\Enums\CommunicationTenantStatus;
use App\Models\CommunicationTenant;
use Illuminate\Console\Command;

class CommunicationTenantMigrateCommand extends Command
{
    protected $signature = 'communication:tenant:migrate
        {orchestra_tenant_id : Orchestra tenant id}
        {--pretend : Validate and display the target without running migrations}
        {--force : Force execution in production}';

    protected $description = 'Run communication tenant migrations for one provisioned tenant database.';

    public function handle(RunTenantMigrationsAction $runTenantMigrations): int
    {
        $tenant = CommunicationTenant::query()
            ->where('orchestra_tenant_id', (string) $this->argument('orchestra_tenant_id'))
            ->first();

        if ($tenant === null) {
            $this->error('Communication tenant was not found.');

            return self::FAILURE;
        }

        if ($tenant->status !== CommunicationTenantStatus::Active->value) {
            $this->error('Communication tenant is not active.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Use --force to run tenant migrations in production.');

            return self::FAILURE;
        }

        $result = $runTenantMigrations->handle(
            tenant: $tenant,
            pretend: (bool) $this->option('pretend'),
            force: (bool) $this->option('force'),
        );

        if ($result->skipped) {
            $this->error($result->error ?? 'Tenant migration skipped.');

            return self::FAILURE;
        }

        if ($result->pretend && $result->success) {
            $this->info('Tenant migration pretend mode.');
            $this->line("Tenant: {$tenant->orchestra_tenant_id}");
            $this->line("Connection: {$result->connectionName}");
            $this->line("Database: {$result->connection?->database_name}");
            $this->line("Path: {$result->migrationPath}");

            return self::SUCCESS;
        }

        if (! $result->success) {
            $this->error('Tenant migrations failed.');

            return self::FAILURE;
        }

        $this->info('Tenant migrations completed.');

        return self::SUCCESS;
    }
}
