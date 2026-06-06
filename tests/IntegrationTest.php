<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Capsule\Manager as Capsule;
use Orchestra\Testbench\TestCase;

class IntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ExportBuilderServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule;
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamps();
        });

        Capsule::schema()->create('roles', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_automatic_custom_relation_mapping()
    {
        $role = Role::create(['name' => 'Admin']);
        $user = User::create(['name' => 'Hasan', 'role_id' => $role->id]);

        $export = new class(['role_id' => 1], []) extends BaseExport {
            public function __construct($filter) {
                parent::__construct([
                    'model' => User::class,
                    'columns' => [
                        'id' => 'int',
                        'name' => 'text',
                        'role_id' => 'int',
                    ],
                    'relations' => ['one' => [], 'many' => ['concat' => [], 'list' => [], 'count' => []]],
                ], $filter);
            }

            public function customRelations(): array {
                return [
                    'role' => ['name']
                ];
            }
        };

        $mapped = $export->map($user);

        // Check that role_id is removed from main columns because customRelations is used
        $this->assertArrayNotHasKey('role_id', $mapped);
        $this->assertEquals(1, $mapped['id']);
        $this->assertEquals('Hasan', $mapped['name']);
        // Check custom relation output
        $this->assertEquals('Admin', $mapped['role_name']);

        $headings = $export->headings();
        $this->assertNotContains('role_id', $headings);
        $this->assertContains('role_name', $headings);
    }
}

class User extends Model {
    protected $fillable = ['name', 'role_id'];
    public function role() {
        return $this->belongsTo(Role::class);
    }
}

class Role extends Model {
    protected $fillable = ['name'];
}
