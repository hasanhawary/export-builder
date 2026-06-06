<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Tests for AdvancedFilter::applyAdvanced().
 *
 * Covers the security-critical allowlist, relation filters,
 * enum resolvers, error handling, and edge cases.
 */
class AdvancedFilterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ExcelServiceProvider::class,
            LaravelMpdfServiceProvider::class,
            ExportBuilderServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('export.namespace', __NAMESPACE__);
        $app['config']->set('export.module.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('af_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamps();
        });

        Schema::create('af_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $role = AfRole::create(['name' => 'Admin']);
        AfUser::create(['name' => 'Alice', 'status' => 'active', 'role_id' => $role->id]);
        AfUser::create(['name' => 'Bob',   'status' => 'inactive', 'role_id' => null]);
    }

    // =========================================================================
    // Security — allowlist
    // =========================================================================

    public function test_unknown_key_is_silently_ignored_and_does_not_inject_sql(): void
    {
        $export = new AfUserExport([
            'advanced' => [['key' => 'nonexistent_column', 'value' => 'evil']],
        ]);

        // Must return all rows — the unknown key was silently dropped
        $this->assertCount(2, iterator_to_array($export->collection()));
    }

    public function test_known_column_key_applies_where_in(): void
    {
        $export = new AfUserExport([
            'advanced' => [['key' => 'status', 'value' => 'active']],
        ]);

        $rows = iterator_to_array($export->collection());
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function test_multiple_values_use_where_in(): void
    {
        $export = new AfUserExport([
            'advanced' => [['key' => 'status', 'value' => ['active', 'inactive']]],
        ]);

        $this->assertCount(2, iterator_to_array($export->collection()));
    }

    public function test_empty_value_array_is_skipped(): void
    {
        $export = new AfUserExport([
            'advanced' => [['key' => 'status', 'value' => []]],
        ]);

        // Empty value → filter skipped → all rows returned
        $this->assertCount(2, iterator_to_array($export->collection()));
    }

    public function test_no_advanced_filters_returns_all_rows(): void
    {
        $export = new AfUserExport([]);
        $this->assertCount(2, iterator_to_array($export->collection()));
    }

    // =========================================================================
    // Relation filter (whereHas)
    // =========================================================================

    public function test_relation_key_applies_where_has(): void
    {
        $export = new AfUserWithRelationExport([
            'advanced' => [['key' => 'role_id', 'value' => 1]],
        ]);

        $rows = iterator_to_array($export->collection());
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function test_relation_filter_with_no_match_returns_empty(): void
    {
        $export = new AfUserWithRelationExport([
            'advanced' => [['key' => 'role_id', 'value' => 999]],
        ]);

        $this->assertCount(0, iterator_to_array($export->collection()));
    }

    // =========================================================================
    // Enum resolver
    // =========================================================================

    public function test_enum_resolver_transforms_value_before_filtering(): void
    {
        $export = new AfUserWithResolverExport([
            'advanced' => [['key' => 'status', 'value' => 'ACTIVE']],
        ]);

        $rows = iterator_to_array($export->collection());
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function test_exception_in_filter_is_logged_and_remaining_filters_still_run(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'ExportBuilder: advanced filter skipped'));

        // 'id' is a real column but we will trigger an error by passing a bad relation config
        $export = new AfUserWithBadRelationExport([
            'advanced' => [
                ['key' => 'bad_relation_key', 'value' => 1],  // will throw
                ['key' => 'status', 'value' => 'active'],      // must still run
            ],
        ]);

        $rows = iterator_to_array($export->collection());
        // The second filter should still apply
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

class AfUser extends Model
{
    protected $table    = 'af_users';
    protected $fillable = ['name', 'status', 'role_id'];

    public function role()
    {
        return $this->belongsTo(AfRole::class, 'role_id');
    }
}

class AfRole extends Model
{
    protected $table    = 'af_roles';
    protected $fillable = ['name'];
}

class AfUserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => AfUser::class,
            'columns' => ['id' => 'int', 'name' => 'text', 'status' => 'text'],
        ], $filter);
    }
}

class AfUserWithRelationExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'           => AfUser::class,
            'columns'         => ['id' => 'int', 'name' => 'text'],
            'filterRelations' => [
                'many' => [
                    'role_id' => ['relation' => 'role', 'column' => 'id'],
                ],
            ],
        ], $filter);
    }
}

class AfUserWithResolverExport extends BaseExport
{
    protected array $resolvers = [
        'status' => ['enum' => AfStatusResolver::class, 'method' => 'fromLabel'],
    ];

    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => AfUser::class,
            'columns' => ['id' => 'int', 'name' => 'text', 'status' => 'text'],
        ], $filter);
    }
}

class AfStatusResolver
{
    public static function fromLabel(string $label): string
    {
        return strtolower($label); // 'ACTIVE' → 'active'
    }
}

class AfUserWithBadRelationExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'           => AfUser::class,
            'columns'         => ['id' => 'int', 'name' => 'text', 'status' => 'text'],
            'filterRelations' => [
                'many' => [
                    // Points to a non-existent relation — will throw inside applyAdvanced
                    'bad_relation_key' => ['relation' => 'nonexistent_relation', 'column' => 'id'],
                ],
            ],
        ], $filter);
    }
}
