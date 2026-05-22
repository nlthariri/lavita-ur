{{--
  Livewire-view — `Dashboard\ManagerWeekChart` (taak 4.2 spec lavita-urenregistratie).

  Requirements: 1.3, 1.10

  Features:
   - ApexCharts staafgrafiek met uren per dag (ma-zo)
   - Gegroepeerd per team (owner) of totaal (manager)
   - Lazy-loaded via Livewire #[Lazy] attribute
   - Skeleton placeholder tijdens laden via placeholder() methode
   - Design tokens: brand-green=#00d4a4, meerdere kleuren voor teams

  Toegankelijkheid (WCAG 2.1 AA):
   - Chart container met role="img" en aria-label
   - Kleurcontrast ≥4.5:1 voor labels
--}}
<section aria-label="Uren per dag grafiek">
    <x-ui.card>
        <x-slot:header>
            <h2 class="text-button-md font-semibold text-ink">Uren per dag deze week</h2>
        </x-slot:header>

        <div
            x-data="managerBarChart({{ \Illuminate\Support\Js::from($chartData) }})"
            x-init="initChart()"
            wire:ignore
        >
            <div
                x-ref="chart"
                class="h-[200px] w-full"
                role="img"
                aria-label="Staafgrafiek uren per dag van de huidige week"
            ></div>
        </div>
    </x-ui.card>
</section>

@script
<script>
/**
 * Alpine.js component — managerBarChart
 *
 * Rendert een ApexCharts staafgrafiek met uren per dag (ma-zo),
 * gegroepeerd per team (owner) of totaal (manager).
 *
 * Requirements: 1.3, 1.10
 * Design tokens: brand-green=#00d4a4, meerdere kleuren voor meerdere teams.
 *
 * Data-formaat (van DashboardAggregationService):
 *   Owner:   { 'Team Alpha': { ma: 480, di: 360, ... }, 'Team Beta': { ... } }
 *   Manager: { 'Totaal': { ma: 480, di: 360, ... } }
 */
Alpine.data('managerBarChart', (chartData) => ({
    chart: null,
    chartData: chartData || {},

    initChart() {
        // Wacht tot ApexCharts geladen is (defer script)
        if (typeof ApexCharts === 'undefined') {
            const checkInterval = setInterval(() => {
                if (typeof ApexCharts !== 'undefined') {
                    clearInterval(checkInterval);
                    this.renderChart();
                }
            }, 100);
            return;
        }
        this.renderChart();
    },

    renderChart() {
        const categories = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
        const teamColors = [
            '#00d4a4', // brand-green
            '#3b82f6', // blauw
            '#f59e0b', // oranje
            '#8b5cf6', // paars
            '#ef4444', // rood
            '#06b6d4', // cyan
        ];

        // Bouw series op basis van chart_data
        const series = [];
        let colorIndex = 0;
        const colors = [];

        for (const [teamName, dayData] of Object.entries(this.chartData)) {
            const data = categories.map(day => dayData[day] || 0);
            series.push({ name: teamName, data: data });
            colors.push(teamColors[colorIndex % teamColors.length]);
            colorIndex++;
        }

        // Fallback als er geen data is
        if (series.length === 0) {
            series.push({ name: 'Totaal', data: [0, 0, 0, 0, 0, 0, 0] });
            colors.push(teamColors[0]);
        }

        const options = {
            chart: {
                type: 'bar',
                height: 200,
                fontFamily: 'Inter, sans-serif',
                toolbar: { show: false },
                animations: {
                    enabled: true,
                    easing: 'easeinout',
                    speed: 400,
                },
            },
            series: series,
            colors: colors,
            xaxis: {
                categories: categories,
                labels: {
                    style: {
                        colors: '#5a5a5c', // steel
                        fontSize: '12px',
                    },
                },
                axisBorder: { color: '#e5e5e5' }, // hairline
                axisTicks: { color: '#e5e5e5' },
            },
            yaxis: {
                labels: {
                    style: {
                        colors: '#5a5a5c',
                        fontSize: '12px',
                    },
                    formatter: function (val) {
                        // Formatteer minuten als uren
                        const hours = Math.floor(val / 60);
                        const mins = Math.round(val % 60);
                        if (mins === 0) return hours + 'u';
                        return hours + 'u ' + mins + 'm';
                    },
                },
            },
            tooltip: {
                y: {
                    formatter: function (val) {
                        const hours = Math.floor(val / 60);
                        const mins = Math.round(val % 60);
                        if (mins === 0) return hours + ' uur';
                        return hours + ' uur ' + mins + ' min';
                    },
                },
            },
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    columnWidth: series.length > 1 ? '70%' : '50%',
                },
            },
            dataLabels: { enabled: false },
            grid: {
                borderColor: '#e5e5e5', // hairline
                strokeDashArray: 4,
            },
            legend: {
                show: series.length > 1,
                position: 'top',
                horizontalAlign: 'left',
                fontSize: '12px',
                fontFamily: 'Inter, sans-serif',
                labels: { colors: '#5a5a5c' },
            },
        };

        this.chart = new ApexCharts(this.$refs.chart, options);
        this.chart.render();
    },

    destroy() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    },
}));
</script>
@endscript
