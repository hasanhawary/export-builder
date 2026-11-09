@php
    /*
     * Default export PDF (mPDF). Publish to customise: php artisan vendor:publish --tag=export-builder-views
     *
     * Variables: $title, $columns, $rows, $settings, $trans_prefix, $start, $end,
     *            $locale, $is_rtl, $generated_at (Carbon), $generated_by (?string),
     *            $brand [name (in this file's language, or null), logo, primary_color, font]
     *
     * Every visible text comes from the translation file in the file's language;
     * numbers and dates are wrapped in <bdo dir="ltr"> so they never reverse in RTL.
     */
    $locale      = $locale ?? app()->getLocale();
    $isRtl       = $is_rtl ?? in_array(substr($locale, 0, 2), ['ar', 'fa', 'he', 'ur'], true);
    $dir         = $isRtl ? 'rtl' : 'ltr';
    $start_align = $isRtl ? 'right' : 'left';
    $end_align   = $isRtl ? 'left' : 'right';

    // Package strings: the configured file first, then the package's own (never a raw key).
    $t = function (string $key) use ($trans_prefix) {
        $line = __("{$trans_prefix}.{$key}");

        return is_string($line) && $line !== "{$trans_prefix}.{$key}" ? $line : __("export::export.{$key}");
    };

    $brand       = is_array($brand ?? null) ? $brand : [];
    $primary     = $brand['primary_color'] ?? '#1e3a5f';
    $company     = $brand['name'] ?? null;
    $logo        = $brand['logo'] ?? null;
    $font        = $brand['font'] ?? 'dejavusanscondensed';
    $safeTitle   = $title ?? $t('export_report');

    $generatedAt = ($generated_at ?? now())->copy()->locale($locale);
    $rows        = $rows ?? [];
    $columns     = $columns ?? [];
    $totalRows   = is_countable($rows) ? count($rows) : 0;
    $totalCols   = max(is_countable($columns) ? count($columns) : 0, 1);

    $label = function ($column, $index) {
        if (is_array($column)) {
            return $column['label'] ?? $column['name'] ?? $column['title'] ?? $column['key'] ?? ('#' . ($index + 1));
        }

        return (string) $column;
    };

    $cell = function ($row, $column, $index) {
        $key = is_array($column) ? ($column['key'] ?? $column['name'] ?? $column['field'] ?? $index) : $index;

        if (is_array($row)) {
            return $row[$key] ?? $row[$index] ?? '';
        }

        return is_object($row) ? data_get($row, $key, '') : '';
    };

    // Numbers, dates and codes stay left-to-right inside Arabic text (never "09-11-2026").
    $ltr = fn ($value) => '<bdo dir="ltr">' . e((string) $value) . '</bdo>';
    $isNumeric = fn ($value) => is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?[\d,]+(\.\d+)?$/', trim($value)));
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $safeTitle }}</title>
    <style>
        @page {
            margin: 14mm 10mm 16mm 10mm;
            footer: html_exportFooter;
        }

        body {
            font-family: {{ $font }}, sans-serif;
            font-size: 9pt;
            color: #1f2937;
            direction: {{ $dir }};
        }

        table { border-collapse: collapse; width: 100%; }

        .header td { vertical-align: middle; }
        .company { font-size: 9pt; color: #6b7280; }
        .title { font-size: 17pt; font-weight: bold; color: {{ $primary }}; }
        .subtitle { font-size: 8.5pt; color: #6b7280; padding-top: 2px; }
        .rule td { height: 3px; background-color: {{ $primary }}; padding: 0; }

        .info { margin-top: 8px; margin-bottom: 10px; }
        .info td {
            background-color: #f3f6fa;
            border: 1px solid #e3e8ef;
            padding: 6px 9px;
            vertical-align: top;
        }
        .info-label { font-size: 7.5pt; color: #6b7280; }
        .info-value { font-size: 10pt; font-weight: bold; color: #111827; padding-top: 1px; }

        .data th {
            background-color: {{ $primary }};
            color: #ffffff;
            font-size: 8pt;
            font-weight: bold;
            padding: 6px 7px;
            text-align: {{ $start_align }};
            border: 1px solid {{ $primary }};
            vertical-align: middle;
        }
        .data td {
            padding: 5px 7px;
            border-bottom: 1px solid #e5e7eb;
            text-align: {{ $start_align }};
            vertical-align: top;
        }
        .data tr.even td { background-color: #f8fafc; }
        .data td.num { text-align: {{ $end_align }}; }
        .empty { text-align: center; color: #9ca3af; padding: 24px; font-size: 10pt; }

        .footer td { font-size: 7.5pt; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 4px; }
    </style>
</head>
<body dir="{{ $dir }}">

<htmlpagefooter name="exportFooter">
    <table class="footer" dir="{{ $dir }}">
        <tr>
            <td style="text-align: {{ $start_align }}; width: 40%;">{{ $company }}</td>
            <td style="text-align: center; width: 20%;">{{ $t('page') }} {PAGENO} {{ $t('of') }} {nbpg}</td>
            <td style="text-align: {{ $end_align }}; width: 40%;">{{ $t('generated_at') }}: {!! $ltr($generatedAt->format('Y-m-d H:i')) !!}</td>
        </tr>
    </table>
</htmlpagefooter>

{{-- Header --}}
<table class="header" dir="{{ $dir }}">
    <tr>
        <td style="text-align: {{ $start_align }}; width: 65%;">
            <div class="title">{{ $safeTitle }}</div>
            <div class="subtitle">{{ $generatedAt->translatedFormat('l j F Y') }}</div>
        </td>
        <td style="text-align: {{ $end_align }}; width: 35%;">
            @if($logo)
                <img src="{{ $logo }}" style="max-height: 14mm; max-width: 45mm;" alt=""><br>
            @endif
            @if($company)
                <span class="company">{{ $company }}</span>
            @endif
        </td>
    </tr>
</table>
<table class="rule"><tr><td></td></tr></table>

{{-- Summary --}}
<table class="info" dir="{{ $dir }}">
    <tr>
        <td>
            <div class="info-label">{{ $t('total_records') }}</div>
            <div class="info-value">{!! $ltr(number_format($totalRows)) !!}</div>
        </td>
        @if(($start ?? null) || ($end ?? null))
            <td>
                <div class="info-label">{{ $t('date_range') }}</div>
                <div class="info-value">
                    @if(($start ?? null) && ($end ?? null))
                        {{ $t('from') }} {!! $ltr($start->format('Y-m-d')) !!} {{ $t('to') }} {!! $ltr($end->format('Y-m-d')) !!}
                    @elseif($start ?? null)
                        {{ $t('from') }} {!! $ltr($start->format('Y-m-d')) !!}
                    @else
                        {{ $t('to') }} {!! $ltr($end->format('Y-m-d')) !!}
                    @endif
                </div>
            </td>
        @endif
        <td>
            <div class="info-label">{{ $t('generated_at') }}</div>
            <div class="info-value">{!! $ltr($generatedAt->format('Y-m-d H:i')) !!}</div>
        </td>
        @if(! empty($generated_by))
            <td>
                <div class="info-label">{{ $t('generated_by') }}</div>
                <div class="info-value">{{ $generated_by }}</div>
            </td>
        @endif
    </tr>
</table>

{{-- Data --}}
<table class="data" dir="{{ $dir }}" repeat_header="1">
    <thead>
    <tr>
        @foreach($columns as $index => $column)
            <th>{{ $label($column, $index) }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse($rows as $rowIndex => $row)
        <tr class="{{ $rowIndex % 2 ? 'even' : '' }}">
            @foreach($columns as $index => $column)
                @php($value = $cell($row, $column, $index))
                @if($isNumeric($value))
                    <td class="num">{!! $ltr($value) !!}</td>
                @else
                    <td>{{ $value }}</td>
                @endif
            @endforeach
        </tr>
    @empty
        <tr>
            <td colspan="{{ $totalCols }}" class="empty">{{ $t('no_data') }}</td>
        </tr>
    @endforelse
    </tbody>
</table>

</body>
</html>
