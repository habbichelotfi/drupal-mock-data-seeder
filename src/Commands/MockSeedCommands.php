<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Commands;

use Drupal\drupal_mock_data_seeder\Service\SeederManager;
use Drush\Commands\DrushCommands;

final class MockSeedCommands extends DrushCommands {

  public function __construct(
    private readonly SeederManager $seederManager,
  ) {
    parent::__construct();
  }

  /**
   * Generate mock content tree(s) from a profile.
   *
   * @command mock:seed
   * @option profile Config profile name.
   * @option bundle Node bundle override.
   * @option count Number of root nodes to create.
   * @option depth Max paragraph nesting depth.
   * @option locale Faker locale (example: fr_FR).
   * @option dry-run Use 1 to simulate without saving entities.
   * @usage drush mock:seed --profile=default --count=20 --depth=3
   */
  public function seed(array $options = [
    'profile' => 'default',
    'bundle' => NULL,
    'count' => NULL,
    'depth' => NULL,
    'locale' => 'fr_FR',
    'dry-run' => '0',
  ]): void {
    $result = $this->seederManager->seed((string) $options['profile'], [
      'bundle' => $options['bundle'],
      'count' => $options['count'],
      'depth' => $options['depth'],
      'locale' => $options['locale'],
      'dry_run' => ((string) $options['dry-run']) === '1',
    ]);

    $this->io()->success(sprintf('Run %s completed (dry-run: %s).', $result['run_id'], $result['dry_run'] ? 'yes' : 'no'));
    $this->io()->table(['Entity type', 'Count'], [
      ['node', (string) $result['stats']['node']],
      ['paragraph', (string) $result['stats']['paragraph']],
      ['taxonomy_term', (string) $result['stats']['taxonomy_term']],
      ['media', (string) $result['stats']['media']],
    ]);
  }

  /**
   * Delete entities created by one seed run.
   *
   * @command mock:reset
   * @option run-id Seed run ID returned by mock:seed.
   * @usage drush mock:reset --run-id=20260901_102030_abcd1234
   */
  public function reset(array $options = [
    'run-id' => NULL,
  ]): void {
    $result = $this->seederManager->reset($options['run-id'] ? (string) $options['run-id'] : NULL);

    $this->io()->success(sprintf('Run %s rollback completed.', $result['run_id']));
    $this->io()->table(['Entity type', 'Deleted'], [
      ['node', (string) $result['deleted']['node']],
      ['paragraph', (string) $result['deleted']['paragraph']],
      ['taxonomy_term', (string) $result['deleted']['taxonomy_term']],
      ['media', (string) $result['deleted']['media']],
    ]);
  }

}

