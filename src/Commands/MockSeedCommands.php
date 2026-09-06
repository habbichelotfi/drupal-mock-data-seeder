<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Commands;

use Drupal\drupal_mock_data_seeder\Service\SeederManager;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for seeding, rollback, and diagnostics.
 */
final class MockSeedCommands extends DrushCommands {

  public function __construct(
    private readonly SeederManager $seederManager,
  ) {
    parent::__construct();
  }

  /**
   * Choose a content type and enable generation for an existing profile.
   *
   * @command mock:setup
   * @option profile Existing profile to configure.
   * @option bundle Node bundle machine name; prompts when omitted.
   * @option create-bundle Use 1 to create a missing node type (without fields).
   * @usage drush mock:setup --bundle=article --create-bundle=1
   */
  public function setup(array $options = ['profile' => 'default', 'bundle' => NULL, 'create-bundle' => '0']): void {
    $bundle = $options['bundle'];
    if (!$bundle) {
      $bundles = $this->seederManager->availableBundles();
      if ($bundles === []) {
        throw new \RuntimeException('No content types exist. Create one in /admin/structure/types/add or run drush mock:setup --bundle=article --create-bundle=1.');
      }
      if (!$this->input()->isInteractive()) {
        throw new \InvalidArgumentException('Non-interactive setup requires --bundle. Available bundles: ' . implode(', ', array_keys($bundles)));
      }
      $bundle = $this->io()->choice('Choose the content type to generate', array_combine(array_keys($bundles), array_keys($bundles)));
    }
    $result = $this->seederManager->setup((string) $options['profile'], (string) $bundle, ((string) $options['create-bundle']) === '1');
    $this->io()->success(sprintf('Seeder enabled. Profile %s now targets %s. Run mock:doctor, then mock:seed --dry-run=1 with this profile.', $result['profile'], $result['bundle']));
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
   * @option seed RNG seed for reproducible runs.
   * @option dry-run Use 1 to simulate without saving entities.
   * @option json Use 1 to output the run report as JSON.
   * @option force Use 1 to override safety guards (count/env).
   * @usage drush mock:seed --profile=default --count=20 --depth=3
   */
  public function seed(
    array $options = [
      'profile' => 'default',
      'bundle' => NULL,
      'count' => NULL,
      'depth' => NULL,
      'locale' => 'fr_FR',
      'seed' => NULL,
      'dry-run' => '0',
      'json' => '0',
      'force' => '0',
    ],
  ): void {
    $result = $this->seederManager->seed((string) $options['profile'], [
      'bundle' => $options['bundle'],
      'count' => $options['count'],
      'depth' => $options['depth'],
      'locale' => $options['locale'],
      'seed' => $options['seed'],
      'dry_run' => ((string) $options['dry-run']) === '1',
      'force' => ((string) $options['force']) === '1',
    ]);

    if (((string) $options['json']) === '1') {
      $this->output()->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      return;
    }

    foreach ($result['warnings'] as $warning) {
      $this->io()->warning($warning);
    }
    $this->io()->success(sprintf('Run %s completed (dry-run: %s).', $result['run_id'], $result['dry_run'] ? 'yes' : 'no'));
    $this->io()->table(['Entity type', 'Count'], [
      ['node', (string) $result['stats']['node']],
      ['paragraph', (string) $result['stats']['paragraph']],
      ['taxonomy_term', (string) $result['stats']['taxonomy_term']],
      ['media', (string) $result['stats']['media']],
    ]);
  }

  /**
   * Validate module prerequisites and safeguards.
   *
   * @command mock:doctor
   * @option profile Config profile name.
   * @option bundle Node bundle override.
   * @option json Use 1 to output diagnostics as JSON.
   * @usage drush mock:doctor --profile=default
   */
  public function doctor(
    array $options = [
      'profile' => 'default',
      'bundle' => NULL,
      'json' => '0',
    ],
  ): void {
    $result = $this->seederManager->doctor(
      (string) $options['profile'],
      $options['bundle'] ? (string) $options['bundle'] : NULL,
    );

    if (((string) $options['json']) === '1') {
      $this->output()->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      return;
    }

    $rows = [];
    foreach ($result['checks'] as $check) {
      $rows[] = [
        (string) $check['check'],
        !empty($check['ok']) ? 'OK' : 'FAIL',
        (string) $check['message'],
      ];
    }

    $this->io()->table(['Check', 'Status', 'Message'], $rows);
    if ($result['ok']) {
      $this->io()->success('mock:doctor reports all checks as OK.');
      return;
    }

    $this->io()->error('mock:doctor detected failing checks.');
  }

  /**
   * Delete entities created by one seed run.
   *
   * @command mock:reset
   * @option run-id Seed run ID returned by mock:seed.
   * @option force Use 1 to reset last run when run-id is required.
   * @usage drush mock:reset --run-id=20260901_102030_abcd1234
   */
  public function reset(
    array $options = [
      'run-id' => NULL,
      'force' => '0',
    ],
  ): void {
    $result = $this->seederManager->reset(
      $options['run-id'] ? (string) $options['run-id'] : NULL,
      ((string) $options['force']) === '1',
    );

    $this->io()->success(sprintf('Run %s rollback completed.', $result['run_id']));
    $this->io()->table(['Entity type', 'Deleted'], [
      ['node', (string) $result['deleted']['node']],
      ['paragraph', (string) $result['deleted']['paragraph']],
      ['taxonomy_term', (string) $result['deleted']['taxonomy_term']],
      ['media', (string) $result['deleted']['media']],
    ]);
  }

}
