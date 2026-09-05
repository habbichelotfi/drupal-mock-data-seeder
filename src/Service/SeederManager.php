<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Faker\Factory;

/**
 * Coordinates seeding, rollback, and environment safeguards.
 */
final class SeederManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly EntityTreeBuilder $entityTreeBuilder,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Runs one seed operation using profile config and runtime overrides.
   */
  public function seed(string $profileName, array $overrides = []): array {
    $config = $this->configFactory->get('drupal_mock_data_seeder.settings');
    if (!$config->get('enabled')) {
      throw new \RuntimeException('Seeder disabled. Set drupal_mock_data_seeder.settings:enabled to true.');
    }

    $force = !empty($overrides['force']);
    $this->assertEnvironmentIsAllowed($force);

    $profile = (array) ($config->get('profiles.' . $profileName) ?? []);
    if ($profile === []) {
      throw new \InvalidArgumentException(sprintf('Unknown profile "%s".', $profileName));
    }

    $count = max(1, (int) ($overrides['count'] ?? $profile['count'] ?? 10));
    $bundle = (string) ($overrides['bundle'] ?? $profile['bundle'] ?? 'article');
    $depth = max(1, (int) ($overrides['depth'] ?? $profile['depth'] ?? 2));
    $locale = (string) ($overrides['locale'] ?? 'fr_FR');
    $dryRun = !empty($overrides['dry_run']);
    $seed = $this->resolveSeed($overrides['seed'] ?? NULL);
    $startedAtMicrotime = microtime(TRUE);

    $this->assertCountWithinLimit($count, $force);
    $this->assertBundleExists($bundle);

    $faker = Factory::create($locale);
    if ($seed !== NULL) {
      // Seed both Faker and PHP RNG so generated trees can be replayed.
      $faker->seed($seed);
      mt_srand($seed);
    }
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
      'seed' => $seed,
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
    $durationMs = (int) round((microtime(TRUE) - $startedAtMicrotime) * 1000);
    $runStore['stats'] = $stats;
    $runStore['duration_ms'] = $durationMs;

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
      'profile' => $profileName,
      'bundle' => $bundle,
      'count' => $count,
      'depth' => $depth,
      'locale' => $locale,
      'seed' => $seed,
      'dry_run' => $dryRun,
      'duration_ms' => $durationMs,
      'stats' => $stats,
    ];
  }

  /**
   * Runs non-destructive prerequisite checks for a target profile.
   */
  public function doctor(string $profileName = 'default', ?string $bundleOverride = NULL): array {
    $config = $this->configFactory->get('drupal_mock_data_seeder.settings');
    $checks = [];

    $enabled = (bool) $config->get('enabled');
    $checks[] = [
      'check' => 'enabled',
      'ok' => $enabled,
      'message' => $enabled
        ? 'Seeder is enabled.'
        : 'Seeder is disabled. Set drupal_mock_data_seeder.settings:enabled to true.',
    ];

    $profile = (array) ($config->get('profiles.' . $profileName) ?? []);
    $profileExists = $profile !== [];
    $checks[] = [
      'check' => 'profile',
      'ok' => $profileExists,
      'message' => $profileExists
        ? sprintf('Profile "%s" exists.', $profileName)
        : sprintf('Unknown profile "%s".', $profileName),
    ];

    $resolvedBundle = (string) ($bundleOverride ?? ($profile['bundle'] ?? 'article'));
    $bundleCheck = $this->bundleCheck($resolvedBundle);
    $checks[] = [
      'check' => 'bundle',
      'ok' => $bundleCheck['ok'],
      'message' => $bundleCheck['message'],
    ];

    $envCheck = $this->environmentCheck();
    $checks[] = [
      'check' => 'environment',
      'ok' => $envCheck['ok'],
      'message' => $envCheck['message'],
    ];

    $tempDir = sys_get_temp_dir();
    $tempWritable = is_string($tempDir) && $tempDir !== '' && is_writable($tempDir);
    $checks[] = [
      'check' => 'temp_dir_writable',
      'ok' => $tempWritable,
      'message' => $tempWritable
        ? sprintf('Temporary directory is writable: %s', $tempDir)
        : sprintf('Temporary directory is not writable: %s', (string) $tempDir),
    ];

    $ok = !in_array(FALSE, array_column($checks, 'ok'), TRUE);

    return [
      'ok' => $ok,
      'profile' => $profileName,
      'bundle' => $resolvedBundle,
      'checks' => $checks,
    ];
  }

  /**
   * Deletes entities from a previous run and clears run state.
   */
  public function reset(?string $runId = NULL, bool $force = FALSE): array {
    $config = $this->configFactory->get('drupal_mock_data_seeder.settings');
    $requireRunId = (bool) $config->get('safeguards.require_run_id_for_reset');
    if ($requireRunId && $runId === NULL && !$force) {
      throw new \InvalidArgumentException('Reset requires --run-id when safeguards.require_run_id_for_reset is enabled. Use --force=1 to override.');
    }

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

  /**
   * Ensures the target node bundle exists.
   */
  private function assertBundleExists(string $bundle): void {
    $storage = $this->entityTypeManager->getStorage('node_type');
    $nodeType = $storage->load($bundle);
    if ($nodeType !== NULL) {
      return;
    }

    $available = array_keys($storage->loadMultiple());
    sort($available);
    $availableText = $available === [] ? '(none)' : implode(', ', $available);
    throw new \InvalidArgumentException(sprintf('Unknown node bundle "%s". Available bundles: %s.', $bundle, $availableText));
  }

  /**
   * Enforces the configured maximum count unless force is enabled.
   */
  private function assertCountWithinLimit(int $count, bool $force): void {
    $config = $this->configFactory->get('drupal_mock_data_seeder.settings');
    $maxCount = max(1, (int) ($config->get('safeguards.max_count') ?? 100));
    if ($count <= $maxCount || $force) {
      return;
    }

    throw new \InvalidArgumentException(sprintf('Requested count %d exceeds safety limit %d. Use --force=1 to override.', $count, $maxCount));
  }

  /**
   * Blocks seeding in configured environments unless forced.
   */
  private function assertEnvironmentIsAllowed(bool $force): void {
    $config = $this->configFactory->get('drupal_mock_data_seeder.settings');
    $blockedEnvs = (array) ($config->get('safeguards.blocked_envs') ?? ['prod', 'production']);
    $blockedEnvs = array_values(array_filter(array_map('strval', $blockedEnvs), static fn(string $env): bool => $env !== ''));
    if ($blockedEnvs === []) {
      return;
    }

    $envVarNames = (array) ($config->get('safeguards.env_var_names') ?? ['DRUPAL_ENV', 'APP_ENV', 'ENVIRONMENT']);
    foreach ($envVarNames as $envVarName) {
      $name = (string) $envVarName;
      if ($name === '') {
        continue;
      }
      $value = getenv($name);
      if (!is_string($value) || $value === '') {
        continue;
      }

      $currentEnv = strtolower(trim($value));
      if (in_array($currentEnv, array_map(static fn(string $env): string => strtolower($env), $blockedEnvs), TRUE) && !$force) {
        throw new \RuntimeException(sprintf('Seeder blocked in environment "%s" (from %s). Use --force=1 to override.', $value, $name));
      }
      return;
    }
  }

  /**
   * Validates and normalizes an optional RNG seed value.
   */
  private function resolveSeed(mixed $seed): ?int {
    if ($seed === NULL || $seed === '') {
      return NULL;
    }

    if (!is_numeric((string) $seed)) {
      throw new \InvalidArgumentException('Seed must be an integer value.');
    }

    $resolved = (int) $seed;
    if ($resolved < 0) {
      throw new \InvalidArgumentException('Seed must be a non-negative integer.');
    }

    return $resolved;
  }

  /**
   * Builds a non-throwing bundle validation result for diagnostics.
   */
  private function bundleCheck(string $bundle): array {
    $storage = $this->entityTypeManager->getStorage('node_type');
    $nodeType = $storage->load($bundle);
    if ($nodeType !== NULL) {
      return [
        'ok' => TRUE,
        'message' => sprintf('Bundle "%s" exists.', $bundle),
      ];
    }

    $available = array_keys($storage->loadMultiple());
    sort($available);
    $availableText = $available === [] ? '(none)' : implode(', ', $available);
    return [
      'ok' => FALSE,
      'message' => sprintf('Unknown node bundle "%s". Available bundles: %s.', $bundle, $availableText),
    ];
  }

  /**
   * Builds a non-throwing environment validation result for diagnostics.
   */
  private function environmentCheck(): array {
    try {
      $this->assertEnvironmentIsAllowed(FALSE);
      return [
        'ok' => TRUE,
        'message' => 'Environment is allowed by safeguards.',
      ];
    }
    catch (\RuntimeException $exception) {
      return [
        'ok' => FALSE,
        'message' => $exception->getMessage(),
      ];
    }
  }

}
