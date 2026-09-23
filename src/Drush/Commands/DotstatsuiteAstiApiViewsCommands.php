<?php

namespace Drupal\dotstatsuite_asti_api_views\Drush\Commands;

use Drupal\dotstatsuite_asti_api_views\SdmxIndicatorImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Syncs dotstatsuite_asti_api_views_indicator from the live
 * dotstatsuite SDMX API.
 */
class DotstatsuiteAstiApiViewsCommands extends DrushCommands {

  public function __construct(
    protected SdmxIndicatorImporter $importer,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new static($container->get('dotstatsuite_asti_api_views.importer'));
  }

  /**
   * Fetches the live ASTI/FAOSTAT dataflow and re-syncs it.
   */
  #[CLI\Command(name: 'dotstatsuite-asti-api-views:import', aliases: ['daaiv'])]
  public function import(): void {
    $count = $this->importer->import();
    if ($count > 0) {
      $this->logger()->success(dt('Imported @count observations into dotstatsuite_asti_api_views_indicator.', ['@count' => $count]));
    }
    else {
      $this->logger()->warning(dt('Import returned no rows — check the log (dblog/watchdog) for the reason.'));
    }
  }

}
