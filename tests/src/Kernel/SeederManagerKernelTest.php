<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mock_data_seeder\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_mock_data_seeder\Service\SeederManager;

/**
 * Basic Kernel tests for the MVP seeder service.
 *
 * @group drupal_mock_data_seeder
 */
final class SeederManagerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'filter',
    'drupal_mock_data_seeder',
  ];

  public function testSeederIsBlockedWhenDisabled(): void {
    /** @var \Drupal\drupal_mock_data_seeder\Service\SeederManager $manager */
    $manager = $this->container->get('drupal_mock_data_seeder.seeder_manager');
    self::assertInstanceOf(SeederManager::class, $manager);

    $this->expectException(\RuntimeException::class);
    $manager->seed('default', ['dry_run' => TRUE]);
  }

  public function testUnknownProfileThrowsException(): void {
    $this->config('drupal_mock_data_seeder.settings')->set('enabled', TRUE)->save();

    /** @var \Drupal\drupal_mock_data_seeder\Service\SeederManager $manager */
    $manager = $this->container->get('drupal_mock_data_seeder.seeder_manager');
    $this->expectException(\InvalidArgumentException::class);
    $manager->seed('missing_profile', ['dry_run' => TRUE]);
  }

  public function testResetRequiresRunIdByDefault(): void {
    /** @var \Drupal\drupal_mock_data_seeder\Service\SeederManager $manager */
    $manager = $this->container->get('drupal_mock_data_seeder.seeder_manager');

    $this->expectException(\InvalidArgumentException::class);
    $manager->reset(NULL, FALSE);
  }

  public function testCountLimitIsEnforced(): void {
    $this->config('drupal_mock_data_seeder.settings')
      ->set('enabled', TRUE)
      ->set('safeguards.max_count', 2)
      ->save();

    /** @var \Drupal\drupal_mock_data_seeder\Service\SeederManager $manager */
    $manager = $this->container->get('drupal_mock_data_seeder.seeder_manager');

    $this->expectException(\InvalidArgumentException::class);
    $manager->seed('default', ['dry_run' => TRUE, 'count' => 3]);
  }

  public function testUnknownBundleThrowsException(): void {
    $this->config('drupal_mock_data_seeder.settings')
      ->set('enabled', TRUE)
      ->set('safeguards.max_count', 100)
      ->save();

    /** @var \Drupal\drupal_mock_data_seeder\Service\SeederManager $manager */
    $manager = $this->container->get('drupal_mock_data_seeder.seeder_manager');

    $this->expectException(\InvalidArgumentException::class);
    $manager->seed('default', ['dry_run' => TRUE, 'bundle' => 'bundle_does_not_exist']);
  }

  public function testDoctorReportsDisabledSeederByDefault(): void {
    /** @var \Drupal\drupal_mock_data_seeder\Service\SeederManager $manager */
    $manager = $this->container->get('drupal_mock_data_seeder.seeder_manager');
    $result = $manager->doctor('default');

    self::assertFalse($result['ok']);
    self::assertSame('enabled', $result['checks'][0]['check']);
    self::assertFalse($result['checks'][0]['ok']);
  }

  public function testDoctorUnknownProfileFailsProfileCheck(): void {
    /** @var \Drupal\drupal_mock_data_seeder\Service\SeederManager $manager */
    $manager = $this->container->get('drupal_mock_data_seeder.seeder_manager');
    $result = $manager->doctor('missing_profile');

    $profileChecks = array_values(array_filter(
      $result['checks'],
      static fn(array $check): bool => $check['check'] === 'profile',
    ));

    self::assertCount(1, $profileChecks);
    self::assertFalse($profileChecks[0]['ok']);
  }

  public function testInvalidSeedThrowsException(): void {
    $this->config('drupal_mock_data_seeder.settings')->set('enabled', TRUE)->save();

    /** @var \Drupal\drupal_mock_data_seeder\Service\SeederManager $manager */
    $manager = $this->container->get('drupal_mock_data_seeder.seeder_manager');

    $this->expectException(\InvalidArgumentException::class);
    $manager->seed('default', ['dry_run' => TRUE, 'seed' => 'invalid-seed']);
  }

}

