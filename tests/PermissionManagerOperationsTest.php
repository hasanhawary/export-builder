<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Support\PermissionManagerOperations;
use Orchestra\Testbench\TestCase;

class PermissionManagerOperationsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ExportBuilderServiceProvider::class];
    }

    private function installed(): PermissionManagerOperations
    {
        return new class extends PermissionManagerOperations {
            protected function permissionManagerInstalled(): bool
            {
                return true;
            }
        };
    }

    public function test_does_nothing_when_permission_manager_is_not_installed(): void
    {
        config()->set('roles.additional_operations', [['name' => 'Log', 'operations' => ['read']]]);

        (new PermissionManagerOperations)->register();

        $this->assertSame([['name' => 'Log', 'operations' => ['read']]], config('roles.additional_operations'));
    }

    public function test_adds_export_file_operations_next_to_existing_ones(): void
    {
        config()->set('roles.additional_operations', [['name' => 'Log', 'operations' => ['read']]]);

        $this->installed()->register();

        $this->assertSame([
            ['name' => 'Log', 'operations' => ['read']],
            ['name' => 'ExportFile', 'operations' => ['create', 'view-all', 'view-own', 'delete']],
        ], config('roles.additional_operations'));
    }

    public function test_works_when_roles_config_has_no_additional_operations(): void
    {
        config()->set('roles', []);

        $this->installed()->register();

        $this->assertSame(
            [['name' => 'ExportFile', 'operations' => ['create', 'view-all', 'view-own', 'delete']]],
            config('roles.additional_operations')
        );
    }

    public function test_project_entry_is_not_overridden_or_duplicated(): void
    {
        $own = [['name' => 'ExportFile', 'operations' => ['view-own']]];
        config()->set('roles.additional_operations', $own);

        $this->installed()->register();
        $this->installed()->register();

        $this->assertSame($own, config('roles.additional_operations'));
    }

    public function test_can_be_turned_off(): void
    {
        config()->set('roles.additional_operations', []);
        config()->set('export.module.permissions.register', false);

        $this->installed()->register();

        $this->assertSame([], config('roles.additional_operations'));
    }

    public function test_skipped_when_export_module_is_disabled(): void
    {
        config()->set('roles.additional_operations', []);
        config()->set('export.module.enabled', false);

        $this->installed()->register();

        $this->assertSame([], config('roles.additional_operations'));
    }

    public function test_operations_follow_renamed_abilities(): void
    {
        config()->set('export.module.permissions.abilities', [
            'export'   => 'export',                       // no "-export-file" suffix → skipped
            'queue'    => 'create-export-file',
            'view_all' => 'read-export-file',
            'view_own' => ['view-own-export-file', 'mine-export-file'],
            'delete'   => 'delete-export-file',
        ]);

        $this->assertSame(
            ['create', 'read', 'view-own', 'mine', 'delete'],
            (new PermissionManagerOperations)->operations()
        );
    }
}
