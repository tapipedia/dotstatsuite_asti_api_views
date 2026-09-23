<?php

namespace Drupal\dotstatsuite_asti_api_views\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Renders a View's result as a Chart.js chart.
 *
 * Fields-based (like Table), no row plugin: three configured fields supply
 * the X-axis labels, the series grouping, and the Y values, pivoted here
 * into Chart.js datasets and handed to a canvas via drupalSettings. Every
 * option (which fields feed the chart, chart type, which series go on the
 * secondary axis) is a normal Views style-plugin option, so a webmaster can
 * reconfigure or duplicate a chart block through Structure > Views exactly
 * like any other display option - no code change needed to point a chart
 * at a different field or filter.
 *
 * Deliberately does not use Views' render/theme pipeline (no preprocess
 * hook, no Twig template): render() returns a plain canvas + drupalSettings
 * directly, since a chart isn't "rows of markup" the normal Views theme
 * layer is built around.
 */
#[ViewsStyle(
  id: 'dotstatsuite_chartjs',
  title: new TranslatableMarkup('Chart.js chart'),
  help: new TranslatableMarkup('Renders the result as a Chart.js line or bar chart.'),
  display_types: ['normal'],
  register_theme: FALSE,
)]
class ChartJs extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesFields = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['chart_type'] = ['default' => 'line'];
    $options['label_field'] = ['default' => ''];
    $options['series_field'] = ['default' => ''];
    $options['value_field'] = ['default' => ''];
    $options['secondary_axis_series'] = ['default' => ''];
    $options['chart_title'] = ['default' => ''];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $field_options = $this->displayHandler->getFieldLabels();

    $form['chart_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Chart title'),
      '#default_value' => $this->options['chart_title'],
    ];
    $form['chart_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Chart type'),
      '#options' => [
        'line' => $this->t('Line'),
        'bar' => $this->t('Bar'),
      ],
      '#default_value' => $this->options['chart_type'],
    ];
    $form['label_field'] = [
      '#type' => 'select',
      '#title' => $this->t('X-axis label field'),
      '#description' => $this->t('The field supplying each point/bar\'s X-axis label - typically a year or date field.'),
      '#options' => $field_options,
      '#default_value' => $this->options['label_field'],
      '#required' => TRUE,
    ];
    $form['series_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Series field'),
      '#description' => $this->t('Distinct values of this field become separate datasets (lines/bar series) - e.g. an indicator name field, so each indicator in the result becomes its own line. Leave as "None" for a single series.'),
      '#options' => ['' => $this->t('- None (single series) -')] + $field_options,
      '#default_value' => $this->options['series_field'],
    ];
    $form['value_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Value field (Y axis)'),
      '#options' => $field_options,
      '#default_value' => $this->options['value_field'],
      '#required' => TRUE,
    ];
    $form['secondary_axis_series'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Series on secondary (right) Y axis'),
      '#description' => $this->t('Comma-separated list of series values (as they appear in the series field) to plot against a secondary right-hand axis - e.g. for a dual-axis chart. Leave empty for a single shared axis.'),
      '#default_value' => $this->options['secondary_axis_series'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $labelField = $this->options['label_field'];
    $seriesField = $this->options['series_field'];
    $valueField = $this->options['value_field'];

    if (!$labelField || !$valueField) {
      return [
        '#markup' => $this->t('This Chart.js block is not fully configured yet - set the label and value fields in its Format settings.'),
      ];
    }

    $rawLabels = [];
    $seriesData = [];
    foreach ($this->view->result as $index => $row) {
      $label = $this->getFieldValue($index, $labelField);
      $value = $this->getFieldValue($index, $valueField);
      $series = $seriesField ? (string) $this->getFieldValue($index, $seriesField) : (string) $this->view->storage->label();

      $rawLabels[] = $label;
      $seriesData[$series][$label] = is_numeric($value) ? (float) $value : NULL;
    }
    $labels = array_values(array_unique($rawLabels));

    $secondaryAxisSeries = array_filter(array_map('trim', explode(',', (string) $this->options['secondary_axis_series'])));

    $datasets = [];
    foreach ($seriesData as $seriesName => $valuesByLabel) {
      $datasets[] = [
        'label' => $seriesName,
        'data' => array_map(static fn($l) => $valuesByLabel[$l] ?? NULL, $labels),
        'axis' => in_array($seriesName, $secondaryAxisSeries, TRUE) ? 'secondary' : 'primary',
      ];
    }

    $chartId = 'views-chartjs-' . $this->view->id() . '-' . $this->view->current_display;
    $config = [
      'type' => $this->options['chart_type'],
      'title' => $this->options['chart_title'],
      'labels' => array_values($labels),
      'datasets' => $datasets,
      'dualAxis' => (bool) $secondaryAxisSeries,
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['views-chartjs-wrapper']],
      'canvas' => [
        '#type' => 'html_tag',
        '#tag' => 'canvas',
        '#attributes' => [
          'id' => $chartId,
          'class' => ['views-chartjs-canvas'],
        ],
      ],
      '#attached' => [
        'library' => ['dotstatsuite_asti_api_views/chartjs'],
        'drupalSettings' => [
          'viewsChartjs' => [
            $chartId => $config,
          ],
        ],
      ],
    ];
  }

}
