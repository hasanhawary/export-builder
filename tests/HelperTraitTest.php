<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\Concerns\HelperTrait;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Tests for HelperTrait::convertValue() — the core type-conversion engine.
 * Every branch of the match() expression is covered, including edge cases.
 */
class HelperTraitTest extends TestCase
{
    use HelperTrait;

    protected function getPackageProviders($app): array
    {
        return [ExportBuilderServiceProvider::class];
    }

    // Stub required by resolveTrans / transFileKey
    private string $filter = '';

    // =========================================================================
    // text
    // =========================================================================

    public function test_text_type_returns_raw_value(): void
    {
        $this->assertSame('hello', $this->convertValue((object)['col' => 'hello'], 'col', 'text'));
    }

    public function test_text_type_returns_empty_string(): void
    {
        $this->assertSame('', $this->convertValue((object)['col' => ''], 'col', 'text'));
    }

    // =========================================================================
    // int
    // =========================================================================

    public function test_int_type_casts_numeric_string(): void
    {
        $this->assertSame(42, $this->convertValue((object)['col' => '42'], 'col', 'int'));
    }

    public function test_int_type_casts_null_to_zero(): void
    {
        $this->assertSame(0, $this->convertValue((object)['col' => null], 'col', 'int'));
    }

    public function test_int_type_casts_float_string(): void
    {
        $this->assertSame(3, $this->convertValue((object)['col' => '3.9'], 'col', 'int'));
    }

    // =========================================================================
    // float
    // =========================================================================

    public function test_float_type_casts_numeric_string(): void
    {
        $this->assertSame(3.14, $this->convertValue((object)['col' => '3.14'], 'col', 'float'));
    }

    public function test_float_type_returns_non_numeric_as_is(): void
    {
        $this->assertSame('n/a', $this->convertValue((object)['col' => 'n/a'], 'col', 'float'));
    }

    // =========================================================================
    // money
    // =========================================================================

    public function test_money_type_formats_to_two_decimal_places(): void
    {
        $this->assertSame('1250.00', $this->convertValue((object)['col' => 1250], 'col', 'money'));
    }

    public function test_money_type_returns_non_numeric_string_as_is(): void
    {
        $this->assertSame('N/A', $this->convertValue((object)['col' => 'N/A'], 'col', 'money'));
    }

    public function test_money_type_rounds_correctly(): void
    {
        $this->assertSame('9.99', $this->convertValue((object)['col' => 9.99], 'col', 'money'));
    }

    // =========================================================================
    // date
    // =========================================================================

    public function test_date_type_formats_to_Y_m_d(): void
    {
        $result = $this->convertValue((object)['col' => '2026-06-05 14:30:00'], 'col', 'date');
        $this->assertSame('2026-06-05', $result);
    }

    public function test_date_type_returns_empty_for_null(): void
    {
        $this->assertSame('', $this->convertValue((object)['col' => null], 'col', 'date'));
    }

    public function test_date_type_returns_empty_for_empty_string(): void
    {
        $this->assertSame('', $this->convertValue((object)['col' => ''], 'col', 'date'));
    }

    // =========================================================================
    // datetime
    // =========================================================================

    public function test_datetime_type_formats_to_Y_m_d_H_i_s(): void
    {
        $result = $this->convertValue((object)['col' => '2026-06-05 14:30:00'], 'col', 'datetime');
        $this->assertSame('2026-06-05 14:30:00', $result);
    }

    public function test_datetime_type_returns_empty_for_null(): void
    {
        $this->assertSame('', $this->convertValue((object)['col' => null], 'col', 'datetime'));
    }

    // =========================================================================
    // bool / boolean
    // =========================================================================

    public function test_bool_true_returns_yes_translation(): void
    {
        $result = $this->convertValue((object)['col' => true], 'col', 'bool');
        $this->assertNotEmpty($result);
        // Must not be the raw boolean
        $this->assertIsString($result);
    }

    public function test_bool_false_returns_no_translation(): void
    {
        $yes = $this->convertValue((object)['col' => true], 'col', 'bool');
        $no  = $this->convertValue((object)['col' => false], 'col', 'bool');
        $this->assertNotSame($yes, $no);
    }

    public function test_boolean_alias_behaves_same_as_bool(): void
    {
        $via_bool    = $this->convertValue((object)['col' => true], 'col', 'bool');
        $via_boolean = $this->convertValue((object)['col' => true], 'col', 'boolean');
        $this->assertSame($via_bool, $via_boolean);
    }

    public function test_bool_with_integer_zero_returns_no(): void
    {
        $no  = $this->convertValue((object)['col' => false], 'col', 'bool');
        $int = $this->convertValue((object)['col' => 0], 'col', 'bool');
        $this->assertSame($no, $int);
    }

    public function test_bool_with_empty_string_returns_no(): void
    {
        $no  = $this->convertValue((object)['col' => false], 'col', 'bool');
        $str = $this->convertValue((object)['col' => ''], 'col', 'bool');
        $this->assertSame($no, $str);
    }

    // =========================================================================
    // array
    // =========================================================================

    public function test_array_type_joins_with_comma(): void
    {
        $result = $this->convertValue((object)['col' => ['a', 'b', 'c']], 'col', 'array');
        $this->assertSame('a , b , c', $result);
    }

    public function test_array_type_with_non_array_returns_value_as_is(): void
    {
        $result = $this->convertValue((object)['col' => 'plain'], 'col', 'array');
        $this->assertSame('plain', $result);
    }

    public function test_array_type_filters_empty_values(): void
    {
        $result = $this->convertValue((object)['col' => ['a', '', 'b']], 'col', 'array');
        $this->assertSame('a , b', $result);
    }

    // =========================================================================
    // classPath
    // =========================================================================

    public function test_class_path_type_returns_class_basename(): void
    {
        $result = $this->convertValue(
            (object)['col' => 'App\\Models\\User'],
            'col',
            'classPath'
        );
        // resolveTrans will return the basename when no translation exists
        $this->assertStringNotContainsString('\\', $result);
    }

    // =========================================================================
    // Enum default branch
    // =========================================================================

    public function test_enum_type_calls_resolve_when_method_exists(): void
    {
        $result = $this->convertValue(
            (object)['col' => 'active'],
            'col',
            HelperTestEnum::class
        );
        $this->assertSame('Active Label', $result);
    }

    public function test_unknown_type_without_resolve_returns_raw_value(): void
    {
        $result = $this->convertValue(
            (object)['col' => 'raw'],
            'col',
            'not_a_real_type'
        );
        $this->assertSame('raw', $result);
    }

    public function test_unknown_class_type_without_resolve_method_returns_raw_value(): void
    {
        $result = $this->convertValue(
            (object)['col' => 'raw'],
            'col',
            \stdClass::class
        );
        $this->assertSame('raw', $result);
    }

    // =========================================================================
    // data_get dot-notation access
    // =========================================================================

    public function test_converts_nested_dot_notation_value(): void
    {
        $object = (object)['address' => (object)['city' => 'Cairo']];
        $result = $this->convertValue($object, 'address.city', 'text');
        $this->assertSame('Cairo', $result);
    }

    public function test_missing_key_returns_empty_string_default(): void
    {
        $result = $this->convertValue((object)[], 'missing', 'text');
        $this->assertSame('', $result);
    }

    // =========================================================================
    // resolveTrans
    // =========================================================================

    public function test_resolve_trans_returns_empty_string_for_null(): void
    {
        $this->assertSame('', $this->resolveTrans(null));
    }

    public function test_resolve_trans_returns_empty_string_for_empty(): void
    {
        $this->assertSame('', $this->resolveTrans(''));
    }

    public function test_resolve_trans_returns_raw_key_when_no_translation(): void
    {
        $result = $this->resolveTrans('no_such_key_xyz');
        $this->assertSame('no_such_key_xyz', $result);
    }

    public function test_resolve_trans_returns_translation_when_found(): void
    {
        // 'api.yes' exists in the package lang file
        config()->set('export.trans_file', 'export');
        app('translator')->addLines(['export.yes' => 'Yes'], 'en');

        $result = $this->resolveTrans('yes');
        $this->assertSame('Yes', $result);
    }
}

// ---------------------------------------------------------------------------
// Test double — Enum with resolve()
// ---------------------------------------------------------------------------
class HelperTestEnum
{
    public static function resolve(string $value): string
    {
        return match ($value) {
            'active' => 'Active Label',
            default  => $value,
        };
    }
}
