<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 14px; margin-bottom: 4px; }
        .meta { color: #666; margin-bottom: 12px; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #2563eb; color: #fff; text-align: left; padding: 4px 6px; font-size: 9px; }
        td { border-bottom: 1px solid #e5e7eb; padding: 3px 6px; }
        tr:nth-child(even) td { background: #f9fafb; }
        .right { text-align: right; }
        tfoot td { font-weight: bold; border-top: 2px solid #2563eb; padding-top: 6px; }
    </style>
</head>
<body>
    <h1>Werkregels Overzicht</h1>
    <p class="meta">Periode: {{ $from }} t/m {{ $to }} &nbsp;|&nbsp; Gegenereerd: {{ $generated_at }}</p>

    <table>
        <thead>
            <tr>
                <th>Medewerker</th>
                <th>Datum</th>
                <th>Start</th>
                <th>Einde</th>
                <th class="right">Pauze (min)</th>
                <th class="right">Netto uren</th>
                <th>Type</th>
                <th>Team</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                <td>{{ $row['medewerker'] }}</td>
                <td>{{ $row['datum'] }}</td>
                <td>{{ $row['start'] }}</td>
                <td>{{ $row['einde'] }}</td>
                <td class="right">{{ $row['pauze_minuten'] }}</td>
                <td class="right">{{ $row['netto_uren'] }}</td>
                <td>{{ $row['type'] }}</td>
                <td>{{ $row['team'] }}</td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center;color:#999;padding:12px;">Geen werkregels gevonden.</td></tr>
            @endforelse
        </tbody>
        @if(count($rows) > 0)
        <tfoot>
            <tr>
                <td colspan="5">Totaal ({{ count($rows) }} regels)</td>
                <td class="right">
                    {{ number_format(array_sum(array_map(fn($r) => str_replace(',', '.', $r['netto_uren']), $rows)), 2, ',', '.') }} uur
                </td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
        @endif
    </table>
</body>
</html>
