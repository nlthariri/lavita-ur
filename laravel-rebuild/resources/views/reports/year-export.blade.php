{{--
  PDF-template — fiscaal jaaroverzicht (taak 12.2 spec
  lavita-urenregistratie).

  Bron:
   - requirements.md 6.7  → "Rapportages & export" met aparte tab
       "Jaaroverzicht" voor fiscale export.
   - requirements.md 14.5 → endpoint `GET /reports/year-export`.
   - requirements.md NFR-9 → fiscale documenten 7 jaar bewaartermijn —
       de jaarexport moet over historische data werken.
   - requirements.md NFR-10 → NL-taal voor alle UI/output.

  Render-context (komt uit
  {@see \App\Services\ReportQueryService::yearExport()}):
   - $year         int       Het rapportjaar.
   - $employees    array     Lijst medewerker-aggregaten met `months[1..12]`
                             en `year_total` per type
                             (WORK/SICK/LEAVE/HOLIDAY/OTHER).
   - $generated_at string    ISO-8601 timestamp Europe/Amsterdam.

  Layout-keuzes:
   - DejaVu Sans (DomPDF-default met breed glyph-bereik en NL-glyphs).
   - 8pt body / 9pt headers — past 13 maand-kolommen + 1 typekolom +
     totaal binnen A4 portrait zonder horizontaal scrollen.
   - Per medewerker eigen `<table>` met `page-break-before: avoid` zodat
     de header en eerste data-rij niet uit elkaar gaan rond een page-
     break (DomPDF respecteert `page-break-inside: avoid`).
   - Minuten worden gerenderd als uren met komma-decimaal (NL-conventie),
     identiek aan {@see \App\Services\ReportQueryService::toReportRows()}.
--}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Jaaroverzicht {{ $year }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            color: #222;
        }
        h1 {
            font-size: 14px;
            margin: 0 0 4px 0;
        }
        h2 {
            font-size: 11px;
            margin: 14px 0 4px 0;
            border-bottom: 1px solid #2563eb;
            padding-bottom: 2px;
        }
        .meta {
            color: #666;
            margin-bottom: 12px;
            font-size: 8px;
        }
        .employee-meta {
            color: #555;
            margin: 0 0 4px 0;
            font-size: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            page-break-inside: avoid;
            margin-bottom: 8px;
        }
        th {
            background: #2563eb;
            color: #fff;
            text-align: right;
            padding: 3px 4px;
            font-size: 8px;
        }
        th.type-col {
            text-align: left;
        }
        td {
            border-bottom: 1px solid #e5e7eb;
            padding: 2px 4px;
            text-align: right;
        }
        td.type-col {
            text-align: left;
            font-weight: bold;
        }
        tr.row-total td {
            border-top: 2px solid #2563eb;
            font-weight: bold;
            background: #f1f5f9;
        }
        .empty {
            color: #999;
            font-style: italic;
            padding: 12px;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>Jaaroverzicht {{ $year }}</h1>
    <p class="meta">Gegenereerd: {{ $generated_at }}</p>

    @php
        // NL-maandafkortingen — gebruikt in de table-headers. We gebruiken
        // expliciete strings i.p.v. `Carbon::shortMonthName()` om
        // taalonafhankelijk en lokalisatie-onafhankelijk te zijn (DomPDF
        // krijgt dezelfde labels ongeacht de server-locale).
        $monthLabels = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mrt', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dec',
        ];

        // Type-rijen renderen we in een vaste volgorde zodat de PDF
        // tussen runs reproduceerbaar is.
        $typeOrder = ['WORK', 'SICK', 'LEAVE', 'HOLIDAY', 'OTHER'];
        $typeLabels = [
            'WORK' => 'Werk',
            'SICK' => 'Ziek',
            'LEAVE' => 'Verlof',
            'HOLIDAY' => 'Feestdag',
            'OTHER' => 'Overig',
        ];

        // Lokale helper: minuten → "h,mm" met NL-decimalen. We
        // dupliceren de logica uit toReportRows() bewust niet 1-op-1,
        // omdat we hier altijd een waarde willen tonen (ook als 0,00).
        $formatHours = static function (int $minutes): string {
            return number_format($minutes / 60, 2, ',', '.');
        };
    @endphp

    @forelse ($employees as $employee)
        <h2>{{ $employee['employee_name'] }}</h2>
        <p class="employee-meta">
            @if (! empty($employee['employee_email']))
                E-mail: {{ $employee['employee_email'] }} &nbsp;|&nbsp;
            @endif
            Team: {{ $employee['team_name'] }}
        </p>

        <table>
            <thead>
                <tr>
                    <th class="type-col">Type</th>
                    @foreach ($monthLabels as $label)
                        <th>{{ $label }}</th>
                    @endforeach
                    <th>Jaartotaal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($typeOrder as $type)
                    <tr>
                        <td class="type-col">{{ $typeLabels[$type] }}</td>
                        @for ($m = 1; $m <= 12; $m++)
                            <td>{{ $formatHours((int) ($employee['months'][$m][$type] ?? 0)) }}</td>
                        @endfor
                        <td>{{ $formatHours((int) ($employee['year_total'][$type] ?? 0)) }}</td>
                    </tr>
                @endforeach
                <tr class="row-total">
                    <td class="type-col">Totaal</td>
                    @for ($m = 1; $m <= 12; $m++)
                        <td>{{ $formatHours((int) ($employee['months'][$m]['total'] ?? 0)) }}</td>
                    @endfor
                    <td>{{ $formatHours((int) ($employee['year_total']['total'] ?? 0)) }}</td>
                </tr>
            </tbody>
        </table>
    @empty
        <p class="empty">Geen werkregels gevonden voor {{ $year }}.</p>
    @endforelse
</body>
</html>
