<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Export' }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }

        .header h1 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .header p {
            font-size: 10px;
            color: #666;
        }

        .logo {
            max-height: 40px;
            margin-bottom: 10px;
        }

        .date-range {
            text-align: right;
            font-size: 10px;
            color: #666;
            margin-bottom: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        thead {
            background-color: #f0f0f0;
            font-weight: bold;
            border-bottom: 1px solid #333;
        }

        th {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }

        td {
            padding: 6px 8px;
            border: 1px solid #ddd;
        }

        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            font-size: 9px;
            color: #666;
            text-align: center;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        @if($settings['logo_url'] ?? null)
            <img src="{{ $settings['logo_url'] }}" alt="Logo" class="logo">
        @endif

        @if($settings['company_name'] ?? null)
            <p>{{ $settings['company_name'] }}</p>
        @endif

        <h1>{{ $title ?? 'Export Report' }}</h1>
    </div>

    @if($start || $end)
        <div class="date-range">
            @if($start && $end)
                {{ __('export::pdf.date_range') }}: {{ $start->format('Y-m-d') }} - {{ $end->format('Y-m-d') }}
            @elseif($start)
                {{ __('export::pdf.from') }}: {{ $start->format('Y-m-d') }}
            @elseif($end)
                {{ __('export::pdf.to') }}: {{ $end->format('Y-m-d') }}
            @endif
        </div>
    @endif

    <table>
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th style="width: {{ $column['width'] }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}" style="text-align: center; font-style: italic;">
                        {{ __('export::pdf.no_data') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>{{ __('export::pdf.generated_at') }}: {{ now()->format('Y-m-d H:i:s') }}</p>
    </div>
</body>
</html>

