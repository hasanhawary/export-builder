<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? __("{$trans_prefix}.export_report") }}</title>

    <style>
        @page {
            margin: 18px 22px 42px 22px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #222222;
            margin: 0;
            padding: 0;
            line-height: 1.45;
            background: #ffffff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
        }

        .text-right {
            text-align: {{ app()->getLocale() === 'ar' ? 'left' : 'right' }};
        }

        .text-left {
            text-align: {{ app()->getLocale() === 'ar' ? 'right' : 'left' }};
        }

        /* ================= HEADER ================= */

        .report-header {
            background-color: #1a1a2e;
            color: #ffffff;
            margin-bottom: 10px;
        }

        .report-header td {
            padding: 16px 20px;
        }

        .header-title {
            font-size: 19px;
            font-weight: bold;
            color: #ffffff;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .header-date {
            font-size: 9px;
            color: #90cdf4;
            margin-top: 4px;
        }

        .company-name {
            font-size: 8.5px;
            color: #a0aec0;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .header-line {
            height: 1px;
            background-color: #34344f;
            margin: 11px 0 8px 0;
        }

        .header-meta td {
            padding: 0;
            font-size: 8.5px;
            color: #a0aec0;
        }

        /* ================= DATE RANGE ================= */

        .date-range {
            margin-bottom: 10px;
        }

        .date-range td {
            background-color: #eef2ff;
            border-left: 4px solid #4f46e5;
            padding: 8px 12px;
            font-size: 9px;
            color: #3730a3;
        }

        html[dir="rtl"] .date-range td {
            border-left: none;
            border-right: 4px solid #4f46e5;
        }

        /* ================= SUMMARY ================= */

        .summary-table {
            margin-bottom: 12px;
            border: 1px solid #e2e8f0;
            table-layout: fixed;
        }

        .summary-table td {
            width: 25%;
            padding: 9px 10px;
            text-align: center;
            background-color: #f8fafc;
            border-right: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        html[dir="rtl"] .summary-table td {
            border-right: none;
            border-left: 1px solid #e2e8f0;
        }

        .summary-table td:last-child {
            border-right: none;
        }

        html[dir="rtl"] .summary-table td:last-child {
            border-left: none;
        }

        .summary-label {
            font-size: 7.5px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: bold;
        }

        .summary-value {
            font-size: 15px;
            font-weight: bold;
            color: #1a1a2e;
            margin-top: 2px;
        }

        .summary-value.small {
            font-size: 10px;
        }

        /* ================= DATA TABLE ================= */

        .data-table {
            width: 100%;
            font-size: 9.5px;
            table-layout: fixed;
            border: 1px solid #e5e7eb;
        }

        .data-table thead {
            display: table-header-group;
        }

        .data-table th {
            background-color: #1a1a2e;
            color: #ffffff;
            padding: 8px 9px;
            text-align: {{ app()->getLocale() === 'ar' ? 'right' : 'left' }};
            font-size: 8.5px;
            font-weight: bold;
            text-transform: uppercase;
            border-right: 1px solid #2d2d4e;
            vertical-align: middle;
            word-wrap: break-word;
        }

        html[dir="rtl"] .data-table th {
            border-right: none;
            border-left: 1px solid #2d2d4e;
        }

        .data-table th:last-child {
            border-right: none;
        }

        html[dir="rtl"] .data-table th:last-child {
            border-left: none;
        }

        .data-table td {
            padding: 7px 9px;
            border-bottom: 1px solid #e5e7eb;
            border-right: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        html[dir="rtl"] .data-table td {
            border-right: none;
            border-left: 1px solid #f3f4f6;
        }

        .data-table td:last-child {
            border-right: none;
        }

        html[dir="rtl"] .data-table td:last-child {
            border-left: none;
        }

        .data-table tbody tr:nth-child(even) td {
            background-color: #f9fafb;
        }

        .data-table tbody tr:last-child td {
            border-bottom: 2px solid #1a1a2e;
        }

        .empty-cell {
            text-align: center;
            padding: 30px;
            color: #94a3b8;
            font-size: 11px;
        }

        /* ================= FOOTER ================= */

        .footer {
            position: fixed;
            bottom: -28px;
            left: 0;
            right: 0;
            height: 28px;
            background-color: #f8fafc;
            border-top: 2px solid #1a1a2e;
            font-size: 8px;
            color: #64748b;
        }

        .footer td {
            padding: 7px 0;
        }
    </style>
</head>

<body>

@php
    $isRtl = app()->getLocale() === 'ar';

    $safeTitle = $title ?? __("{$trans_prefix}.export_report");

    $totalRows = is_countable($rows ?? []) ? count($rows) : 0;
    $totalColumns = is_countable($columns ?? []) ? count($columns) : 0;

    $getColumnLabel = function ($column, $index) {
        if (is_array($column)) {
            return $column['label']
                ?? $column['name']
                ?? $column['title']
                ?? $column['key']
                ?? ('Column ' . ($index + 1));
        }

        return (string) $column;
    };

    $getColumnKey = function ($column, $index) {
        if (is_array($column)) {
            return $column['key']
                ?? $column['name']
                ?? $column['field']
                ?? $column['attribute']
                ?? $index;
        }

        return $column;
    };

    $getCellValue = function ($row, $column, $index) use ($getColumnKey) {
        $key = $getColumnKey($column, $index);

        if (is_array($row)) {
            return $row[$key] ?? $row[$index] ?? '';
        }

        if (is_object($row)) {
            return data_get($row, $key, '');
        }

        return '';
    };
@endphp

{{-- ================= HEADER ================= --}}
<table class="report-header" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <table cellpadding="0" cellspacing="0">
                <tr>
                    <td style="width: 45%; vertical-align: middle; padding: 0;">
                        @if($settings['logo_url'] ?? null)
                            <img src="{{ $settings['logo_url'] }}"
                                 style="max-height: 34px; max-width: 150px; margin-bottom: 5px;" alt="">
                            <br>
                        @endif

                        @if($settings['company_name'] ?? null)
                            <span class="company-name">
                                {{ $settings['company_name'] }}
                            </span>
                        @endif
                    </td>

                    <td class="text-right" style="width: 55%; vertical-align: middle; padding: 0;">
                        <div class="header-title">
                            {{ $safeTitle }}
                        </div>
                        <div class="header-date">
                            {{ now()->format('l, F j, Y') }}
                        </div>
                    </td>
                </tr>
            </table>

            <div class="header-line"></div>

            <table class="header-meta" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="text-left">
                        {{ __("{$trans_prefix}.generated_at") }}:
                        {{ now()->format('Y-m-d H:i:s') }}
                    </td>

                    <td class="text-right">
                        {{ $totalRows }} {{ __("{$trans_prefix}.records") }}
                        &nbsp;|&nbsp;
                        {{ $totalColumns }} {{ __("{$trans_prefix}.columns") }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ================= DATE RANGE ================= --}}
@if(($start ?? null) || ($end ?? null))
    <table class="date-range" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <strong>{{ __("{$trans_prefix}.date_range") }}:</strong>&nbsp;

                @if(($start ?? null) && ($end ?? null))
                    {{ $start->format('Y-m-d') }} &nbsp;→&nbsp; {{ $end->format('Y-m-d') }}
                @elseif($start ?? null)
                    {{ __("{$trans_prefix}.from") }} {{ $start->format('Y-m-d') }}
                @elseif($end ?? null)
                    {{ __("{$trans_prefix}.to") }} {{ $end->format('Y-m-d') }}
                @endif
            </td>
        </tr>
    </table>
@endif

{{-- ================= SUMMARY ================= --}}
<table class="summary-table" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <div class="summary-label">
                {{ __("{$trans_prefix}.total_records") }}
            </div>
            <div class="summary-value">
                {{ $totalRows }}
            </div>
        </td>

        <td>
            <div class="summary-label">
                {{ __("{$trans_prefix}.columns") }}
            </div>
            <div class="summary-value">
                {{ $totalColumns }}
            </div>
        </td>

        <td>
            <div class="summary-label">
                {{ __("{$trans_prefix}.format") }}
            </div>
            <div class="summary-value small">
                PDF
            </div>
        </td>

        <td>
            <div class="summary-label">
                {{ __("{$trans_prefix}.date") }}
            </div>
            <div class="summary-value small">
                {{ now()->format('Y-m-d') }}
            </div>
        </td>
    </tr>
</table>

{{-- ================= DATA TABLE ================= --}}
<table class="data-table" cellpadding="0" cellspacing="0">
    <thead>
    <tr>
        @foreach($columns as $index => $column)
            <th>
                {{ $getColumnLabel($column, $index) }}
            </th>
        @endforeach
    </tr>
    </thead>

    <tbody>
    @forelse($rows as $row)
        <tr>
            @foreach($columns as $index => $column)
                <td>
                    {{ $getCellValue($row, $column, $index) }}
                </td>
            @endforeach
        </tr>
    @empty
        <tr>
            <td colspan="{{ max($totalColumns, 1) }}" class="empty-cell">
                {{ __("{$trans_prefix}.no_data") }}
            </td>
        </tr>
    @endforelse
    </tbody>
</table>

{{-- ================= FOOTER ================= --}}
<table class="footer" cellpadding="0" cellspacing="0">
    <tr>
        <td class="text-left" style="width: 50%;">
            {{ $settings['company_name'] ?? config('app.name', 'Export Builder') }}
        </td>

        <td class="text-right" style="width: 50%;">
            {{ __("{$trans_prefix}.generated_at") }}:
            {{ now()->format('Y-m-d H:i:s') }}
        </td>
    </tr>
</table>

</body>
</html>