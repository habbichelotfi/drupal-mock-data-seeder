<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Faker\Factory;

final class SeederManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly EntityTreeBuilder $entityTreeBuilder,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function seed(string $profileName, array $overrides = []): array {
    $config = $this->configFactory->get('drupal_mock_data_seeder.settings');
    if (!$config->get('enabled')) {
      throw new \RuntimeException('Seeder disabled. Set drupal_mock_data_seeder.settings:enabled to true.');
    }

    $profile = (array) ($config->get('profiles.' . $profileName) ?? []);
    if ($profile === []) {
      throw new \InvalidArgumentException(sprintf('Unknown profile "%s".', $profileName));
    }

    $count = max(1, (int) ($overrides['count'] ?? $profile['count'] ?? 10));
    $bundle = (string) ($overrides['bundle'] ?? $profile['bundle'] ?? 'article');
    $depth = max(1, (int) ($overrides['depth'] ?? $profile['depth'] ?? 2));
    $locale = (string) ($overrides['locale'] ?? 'fr_FR');
    $dryRun = !empty($overrides['dry_run']);

    $faker = Factory::create($locale);
    $runId = date('Ymd_His') . '_' . substr(hash('sha256', uniqid((string) mt_rand(), TRUE)), 0, 8);
    $stats = [
      'node' => 0,
      'paragraph' => 0,
      'taxonomy_term' => 0,
      'media' => 0,
    ];

    $runStore = [
      'profile' => $profileName,
      'bundle' => $bundle,
      'created' => [
        'node' => [],
        'paragraph' => [],
        'taxonomy_term' => [],
        'media' => [],
      ],
      'started_at' => date(DATE_ATOM),
      'dry_run' => $dryRun,
    ];

    for ($i = 0; $i < $count; $i++) {
      $created = $this->entityTreeBuilder->buildNodeTree($bundle, $profile, $depth, $faker, $dryRun);
      foreach ($created as $entityType => $ids) {
        $stats[$entityType] += count($ids);
        if (!$dryRun) {
          $runStore['created'][$entityType] = array_values(array_unique(array_merge($runStore['created'][$entityType], $ids)));
        }
      }
    }

    $runStore['finished_at'] = date(DATE_ATOM);
    $runStore['stats'] = $stats;

    if (!$dryRun) {
      $this->state->set('drupal_mock_data_seeder.runs.' . $runId, $runStore);
      $this->state->set('drupal_mock_data_seeder.last_run_id', $runId);
    }

    $this->loggerFactory->get('drupal_mock_data_seeder')->notice(
      'Mock seed run {run_id}: profile={profile}, bundle={bundle}, nodes={nodes}, paragraphs={paragraphs}, terms={terms}, media={media}, dry-run={dry_run}',
      [
        'run_id' => $runId,
        'profile' => $profileName,
        'bundle' => $bundle,
        'nodes' => $stats['node'],
        'paragraphs' => $stats['paragraph'],
        'terms' => $stats['taxonomy_term'],
        'media' => $stats['media'],
        'dry_run' => $dryRun ? 'yes' : 'no',
      ],
    );

    return [
      'run_id' => $runId,
      'dry_run' => $dryRun,
      'stats' => $stats,
    ];
  }

  public function reset(?string $runId = NULL): array {
    $runId = $runId ?: (string) $this->state->get('drupal_mock_data_seeder.last_run_id', '');
    if ($runId === '') {
      throw new \InvalidArgumentException('No run ID provided and no previous run found.');
    }

    $runStore = $this->state->get('drupal_mock_data_seeder.runs.' . $runId);
    if (!is_array($runStore) || empty($runStore['created'])) {
      throw new \InvalidArgumentException(sprintf('Unknown run ID "%s".', $runId));
    }

    $deleted = [
      'node' => 0,
      'paragraph' => 0,
      'taxonomy_term' => 0,
      'media' => 0,
    ];

    foreach (['node', 'paragraph', 'media', 'taxonomy_term'] as $entityType) {
      $ids = array_map('intval', $runStore['created'][$entityType] ?? []);
      if ($ids === [] || !$this->entityTypeManager->hasDefinition($entityType)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entityType);
      $entities = $storage->loadMultiple($ids);
      if ($entities !== []) {
        $storage->delete($entities);
        $deleted[$entityType] = count($entities);
      }
    }

    $this->state->delete('drupal_mock_data_seeder.runs.' . $runId);

    return [
      'run_id' => $runId,
      'deleted' => $deleted,
    ];
  }

}

