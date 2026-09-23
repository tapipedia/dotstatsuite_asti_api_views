<?php

namespace Drupal\dotstatsuite_asti_api_views;

use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Syncs dotstatsuite_asti_api_views_indicator from the live dotstatsuite
 * SDMX API.
 *
 * Fetches the ASTI:DF_ASTI_FAO_INDICATORS dataflow (134 countries x 3
 * indicators) as SDMX-JSON and the CL_ASTI_FAO_INDICATOR codelist for
 * human-readable indicator names, then replaces the table contents.
 *
 * Uses the JSON data format, not CSV: this dataflow's SDMX-CSV export
 * returns HTTP 500 (traced to a data-quality artifact in its uploaded
 * UNIT_MEASURE attribute - an unexpected "No" value alongside the
 * expected "million USD"). The JSON export of the same data is
 * unaffected, so this importer parses SDMX-JSON 2.0.0 series/observations
 * directly instead of flat CSV rows.
 *
 * Same safe-sync shape as the sibling ASTI modules' importers: import()
 * never throws - any failure (network, HTTP, parse, DB) is logged and
 * leaves the previously-synced data untouched, so a dotstatsuite outage
 * can never break the /dotstatsuite_asti_api_views page or a cron run.
 * The label fetch is a further best-effort layer on top of that - its own
 * failure only drops labels for this run, it never blocks the data sync
 * itself.
 */
class SdmxIndicatorImporter {

  protected const DATA_URL = 'https://api-dotstatsuite.tapipedia.org/design/rest/data/ASTI,DF_ASTI_FAO_INDICATORS,1.0/all';
  protected const INDICATOR_CODELIST_URL = 'https://api-dotstatsuite.tapipedia.org/design/rest/codelist/ASTI/CL_ASTI_FAO_INDICATOR/1.0';

  public function __construct(
    protected ClientInterface $httpClient,
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Fetches and re-syncs the indicator table.
   *
   * @return int
   *   Number of rows imported, or 0 on failure.
   */
  public function import(): int {
    try {
      $response = $this->httpClient->request('GET', self::DATA_URL, [
        'headers' => ['Accept' => 'application/vnd.sdmx.data+json;version=2.0.0'],
        'connect_timeout' => 5,
        'timeout' => 30,
      ]);

      $rows = $this->parseSdmxJson((string) $response->getBody());
      if (!$rows) {
        $this->logger->warning('ASTI/FAOSTAT fetch succeeded but produced no rows; leaving existing data in place.');
        return 0;
      }

      $labels = $this->fetchIndicatorLabels();
      foreach ($rows as &$row) {
        $row['indicator_label'] = $labels[$row['indicator']] ?? NULL;
      }
      unset($row);

      // Synthetic derived series: year-over-year % change in researchers,
      // computed independently per country. Not part of the dotstatsuite
      // dataflow itself - computed here so the Views chart blocks can
      // chart it like any other indicator, with no special-casing in the
      // style plugin.
      $rows = array_merge($rows, $this->computeGrowthRateRows($rows));

      // DELETE (not TRUNCATE) so this stays inside the transaction: MySQL
      // auto-commits TRUNCATE even mid-transaction, which would have left
      // the table empty if an INSERT below failed partway through.
      $transaction = $this->database->startTransaction();
      $this->database->delete('dotstatsuite_asti_api_views_indicator')->execute();
      foreach ($rows as $row) {
        $this->database->insert('dotstatsuite_asti_api_views_indicator')->fields($row)->execute();
      }
      unset($transaction);

      $this->logger->notice('Imported @count observations from the ASTI/FAOSTAT dataflow.', ['@count' => count($rows)]);
      return count($rows);
    }
    catch (GuzzleException $e) {
      $this->logChallenge($e);
      $this->logger->error('Failed to fetch ASTI/FAOSTAT data: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
    catch (\Throwable $e) {
      $this->logger->error('ASTI/FAOSTAT import failed: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Parses an SDMX-JSON 2.0.0 data message into flat rows.
   *
   * Walks `data.structures[0].dimensions.series` (ordered by keyPosition)
   * to decode each "i:j[:...]" series key into dimension code values, and
   * `data.structures[0].dimensions.observation[0]` to decode each
   * observation key into a time period. Attribute values are resolved the
   * same way via `data.structures[0].attributes.observation`, using the
   * index carried alongside the observation value.
   *
   * Deliberately generic over dimension order/count rather than assuming
   * REF_AREA/INDICATOR are always dimensions 0/1 - this dataflow's shape
   * could change without this parser silently mis-mapping values.
   */
  private function parseSdmxJson(string $json): array {
    $data = json_decode($json, TRUE, flags: JSON_THROW_ON_ERROR);

    $structure = $data['data']['structures'][0] ?? NULL;
    $dataSet = $data['data']['dataSets'][0] ?? NULL;
    if (!$structure || !$dataSet) {
      return [];
    }

    $seriesDims = $structure['dimensions']['series'] ?? [];
    usort($seriesDims, static fn($a, $b) => ($a['keyPosition'] ?? 0) <=> ($b['keyPosition'] ?? 0));

    $obsDim = $structure['dimensions']['observation'][0] ?? NULL;
    $obsAttrs = $structure['attributes']['observation'] ?? [];

    if (!$obsDim || !isset($seriesDims[0], $seriesDims[1])) {
      return [];
    }

    $refAreaPos = NULL;
    $indicatorPos = NULL;
    foreach ($seriesDims as $pos => $dim) {
      if ($dim['id'] === 'REF_AREA') {
        $refAreaPos = $pos;
      }
      elseif ($dim['id'] === 'INDICATOR') {
        $indicatorPos = $pos;
      }
    }
    if ($refAreaPos === NULL || $indicatorPos === NULL) {
      return [];
    }

    $rows = [];
    foreach ($dataSet['series'] ?? [] as $seriesKey => $series) {
      $components = explode(':', $seriesKey);
      $refArea = $seriesDims[$refAreaPos]['values'][(int) $components[$refAreaPos]]['id'] ?? NULL;
      $indicator = $seriesDims[$indicatorPos]['values'][(int) $components[$indicatorPos]]['id'] ?? NULL;
      if (!$refArea || !$indicator) {
        continue;
      }

      foreach ($series['observations'] ?? [] as $obsKey => $observation) {
        $timePeriod = $obsDim['values'][(int) $obsKey]['id'] ?? NULL;
        if ($timePeriod === NULL) {
          continue;
        }

        $value = $observation[0] ?? NULL;
        $unitMeasure = NULL;
        foreach ($obsAttrs as $attrIndex => $attr) {
          $valueIndex = $observation[$attrIndex + 1] ?? NULL;
          if ($attr['id'] === 'UNIT_MEASURE' && $valueIndex !== NULL) {
            $unitMeasure = $attr['values'][$valueIndex]['value'] ?? NULL;
          }
        }

        $rows[] = [
          'ref_area' => $refArea,
          'indicator' => $indicator,
          'time_period' => (int) $timePeriod,
          'obs_value' => is_numeric($value) ? (float) $value : NULL,
          'unit_measure' => ($unitMeasure ?? '') !== '' ? $unitMeasure : NULL,
        ];
      }
    }
    return $rows;
  }

  /**
   * Computes a synthetic RESEARCHERS_FTE_GROWTH_PCT series, per country.
   *
   * Year-over-year % change in RESEARCHERS_FTE, one row per country/year
   * after that country's first synced year. This is derived from the real
   * synced data, not itself fetched from dotstatsuite - kept separate from
   * parseSdmxJson() so a bug here can never affect the real observations.
   *
   * @return array
   *   Rows in the same shape as parseSdmxJson()'s output.
   */
  private function computeGrowthRateRows(array $rows): array {
    $researchersByCountry = [];
    foreach ($rows as $row) {
      if ($row['indicator'] === 'RESEARCHERS_FTE' && $row['obs_value'] !== NULL) {
        $researchersByCountry[$row['ref_area']][$row['time_period']] = $row['obs_value'];
      }
    }

    $growthRows = [];
    foreach ($researchersByCountry as $refArea => $researchers) {
      ksort($researchers);
      $prevYear = NULL;
      $prevValue = NULL;
      foreach ($researchers as $year => $value) {
        if ($prevYear !== NULL && $prevYear === $year - 1 && $prevValue) {
          $growthRows[] = [
            'ref_area' => $refArea,
            'indicator' => 'RESEARCHERS_FTE_GROWTH_PCT',
            'indicator_label' => 'Agricultural researchers, annual growth rate (%)',
            'time_period' => $year,
            'obs_value' => round((($value / $prevValue) - 1) * 100, 2),
            'unit_measure' => '%',
          ];
        }
        $prevYear = $year;
        $prevValue = $value;
      }
    }
    return $growthRows;
  }

  /**
   * Fetches indicator code => human-readable name from the live codelist.
   *
   * Deliberately separate from import()'s own try/catch: this is a
   * secondary, best-effort enrichment, not something that should ever turn
   * a successful data sync into a failed one.
   *
   * @return array<string, string>
   *   Indicator code to name, or an empty array on any failure.
   */
  private function fetchIndicatorLabels(): array {
    try {
      $response = $this->httpClient->request('GET', self::INDICATOR_CODELIST_URL, [
        'headers' => ['Accept' => 'application/vnd.sdmx.structure+json;version=2.0.0'],
        'connect_timeout' => 5,
        'timeout' => 15,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
      $codes = $data['data']['codelists'][0]['codes'] ?? [];

      $labels = [];
      foreach ($codes as $code) {
        if (isset($code['id'], $code['name'])) {
          $labels[$code['id']] = $code['name'];
        }
      }
      return $labels;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not fetch indicator labels from CL_ASTI_FAO_INDICATOR; indicator codes will show without labels this run: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Logs a distinct message when Cloudflare served a challenge page.
   */
  private function logChallenge(GuzzleException $e): void {
    if (!method_exists($e, 'getResponse') || !$e->getResponse()) {
      return;
    }
    $mitigated = $e->getResponse()->getHeaderLine('cf-mitigated');
    if ($mitigated !== '') {
      $this->logger->warning('ASTI/FAOSTAT request was Cloudflare-challenged (cf-mitigated: @value) instead of returning data.', ['@value' => $mitigated]);
    }
  }

}
