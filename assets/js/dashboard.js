document.addEventListener('DOMContentLoaded', function () {
    if (!window.smiData || !window.smiData.charts) return;

    var container = document.getElementById('smi-charts-container');
    if (!container) return;

    function escapeHtml(s) {
        if (!s && s !== 0) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    smiData.charts.forEach(function (chartDef) {
        var wrap = document.createElement('div');
        wrap.className = 'smi-chart-wrap';
        var h3 = document.createElement('h3');
        h3.textContent = chartDef.title;
        var chartDiv = document.createElement('div');
        chartDiv.id = chartDef.id;
        wrap.appendChild(h3);
        wrap.appendChild(chartDiv);
        container.appendChild(wrap);

        // Separate step-line series (Wayback bucketed) from normal series
        var normalSeries = [];
        var stepSeries   = [];

        chartDef.series.forEach(function (s) {
            var hasStep = s.data.some(function (d) { return d.stepline; });
            if (hasStep) {
                stepSeries.push(s);
            } else {
                normalSeries.push(s);
            }
        });

        // Build ApexCharts series with confidence-based dash styling
        function buildSeries(seriesList) {
            return seriesList.map(function (s) {
                return {
                    name: s.name,
                    data: s.data.map(function (d) {
                        return { x: new Date(d.x).getTime(), y: d.y };
                    }),
                };
            });
        }

        var allSeries = normalSeries.concat(stepSeries);

        var options = {
            chart:  { type: 'line', height: 300, zoom: { enabled: true } },
            stroke: {
                curve: allSeries.map(function (s, i) {
                    return i >= normalSeries.length ? 'stepline' : 'smooth';
                }),
                dashArray: allSeries.map(function (s) {
                    var conf = s.data[0] ? s.data[0].confidence : 'high';
                    return { ground_truth: 0, high: 0, medium: 6, low: 4, manual: 4 }[conf] || 0;
                }),
            },
            markers: {
                size: allSeries.map(function (s) {
                    var conf = s.data[0] ? s.data[0].confidence : 'high';
                    return (conf === 'manual' || conf === 'low') ? 5 : 0;
                }),
                shape: 'circle',
                fillOpacity: allSeries.map(function (s) {
                    var conf = s.data[0] ? s.data[0].confidence : 'high';
                    return (conf === 'manual') ? 0 : 1; // hollow for manual
                }),
            },
            series: buildSeries(allSeries),
            xaxis:  { type: 'datetime' },
            tooltip: {
                custom: function ({ series, seriesIndex, dataPointIndex, w }) {
                    var s   = allSeries[seriesIndex];
                    var d   = s && s.data[dataPointIndex];
                    var note = (d && d.stepline) ? '<br><em>WP.org bucketed figure</em>' : '';
                    var txt  = (d && d.value_text) ? '<br>' + escapeHtml(d.value_text) : '';
                    var conf = (d && d.confidence) ? '<br>Confidence: ' + escapeHtml(d.confidence) : '';
                    return '<div style="padding:8px">' + escapeHtml(series[seriesIndex][dataPointIndex]) + txt + conf + note + '</div>';
                },
            },
        };

        var chart = new ApexCharts(document.getElementById(chartDef.id), options);
        chart.render();
    });
});
