(function (Drupal, drupalSettings, once) {
  'use strict';

  var PALETTE = ['#2563eb', '#16a34a', '#e11d48', '#f4a6c1', '#f59e0b', '#7c3aed'];

  function buildDatasets(config) {
    return config.datasets.map(function (dataset, i) {
      var color = PALETTE[i % PALETTE.length];
      return {
        label: dataset.label,
        data: dataset.data,
        yAxisID: dataset.axis === 'secondary' ? 'y1' : 'y',
        borderColor: color,
        backgroundColor: config.type === 'bar' ? color : color + '22',
        tension: 0.35,
        pointRadius: config.type === 'line' ? 2 : undefined,
        fill: config.type === 'line'
      };
    });
  }

  function buildScales(config) {
    if (!config.dualAxis) {
      return {};
    }
    return {
      y: { type: 'linear', position: 'left' },
      y1: { type: 'linear', position: 'right', grid: { drawOnChartArea: false } }
    };
  }

  Drupal.behaviors.dotstatsuiteViewsChartjs = {
    attach: function (context, settings) {
      var charts = (settings.viewsChartjs) || {};
      Object.keys(charts).forEach(function (chartId) {
        once('views-chartjs-' + chartId, '#' + chartId, context).forEach(function (canvas) {
          var config = charts[chartId];
          new Chart(canvas.getContext('2d'), {
            type: config.type === 'bar' ? 'bar' : 'line',
            data: {
              labels: config.labels,
              datasets: buildDatasets(config)
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              interaction: { mode: 'index', intersect: false },
              plugins: {
                legend: { position: 'top', display: config.datasets.length > 1 },
                title: { display: !!config.title, text: config.title }
              },
              scales: buildScales(config)
            }
          });
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
