<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mock_data_seeder\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\drupal_mock_data_seeder\Service\EntityTreeBuilder;
use Drupal\drupal_mock_data_seeder\Service\FieldValueGenerator;
use Drupal\drupal_mock_data_seeder\Service\SeederManager;
use PHPUnit\Framework\TestCase;

/**
 * Exercises setup and failed-run cleanup using the real service workflow.
 *
 * @group drupal_mock_data_seeder
 */
final class SeederWorkflowTest extends TestCase {

  /**
   * Builds a manager with in-memory configuration and run storage.
   */
  private function manager(EntityTypeManagerInterface $types, array &$stateData, array &$settings): SeederManager {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static fn($key) => $settings[$key] ?? NULL);
    $config->method('set')->willReturnCallback(static function ($key, $value) use (&$settings, $config) {
      $settings[$key] = $value;
      return $config;
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $factory->method('getEditable')->willReturn($config);
    $state = $this->createMock(StateInterface::class);
    $state->method('set')->willReturnCallback(static function ($key, $value) use (&$stateData): void {
      $stateData[$key] = $value;
    });
    $state->method('get')->willReturnCallback(static function ($key, $default = NULL) use (&$stateData) {
      return $stateData[$key] ?? $default;
    });
    $state->method('delete')->willReturnCallback(static function ($key) use (&$stateData): void {
      unset($stateData[$key]);
    });
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    return new SeederManager($types, $state, $logger, new EntityTreeBuilder($types, new FieldValueGenerator()), $factory);
  }

  /**
   * Setup chooses an existing type and enables the requested profile.
   */
  public function testSetupExistingBundle(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('page')->willReturn($this->createMock(EntityInterface::class));
    $storage->expects(self::never())->method('create');
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('getStorage')->with('node_type')->willReturn($storage);
    $state = [];
    $settings = ['profiles.default' => ['bundle' => 'article'], 'safeguards.blocked_envs' => []];
    $manager = $this->manager($types, $state, $settings);
    $manager->setup('default', 'page');
    self::assertTrue($settings['enabled']);
    self::assertSame('page', $settings['profiles.default.bundle']);
  }

  /**
   * A missing type is created only when explicitly requested.
   */
  public function testSetupCreatesBundleExplicitly(): void {
    $bundle = $this->createMock(EntityInterface::class);
    $bundle->expects(self::once())->method('save');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnOnConsecutiveCalls(NULL, $bundle);
    $storage->expects(self::once())->method('create')->with(['type' => 'test_page', 'name' => 'Test page'])->willReturn($bundle);
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('getStorage')->willReturn($storage);
    $state = [];
    $settings = ['profiles.default' => ['bundle' => 'article'], 'safeguards.blocked_envs' => []];
    $manager = $this->manager($types, $state, $settings);
    $manager->setup('default', 'test_page', TRUE);
    self::assertTrue($settings['enabled']);
  }

  /**
   * Failed setup leaves the configuration unchanged.
   */
  public function testMissingBundleDoesNotEnableSeeder(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([]);
    $storage->expects(self::never())->method('create');
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('getStorage')->willReturn($storage);
    $state = [];
    $settings = ['profiles.default' => ['bundle' => 'article'], 'safeguards.blocked_envs' => []];
    $manager = $this->manager($types, $state, $settings);
    try {
      $manager->setup('default', 'missing');
      self::fail('Expected setup to reject an unknown bundle.');
    }
    catch (\InvalidArgumentException) {
      self::assertArrayNotHasKey('enabled', $settings);
    }
  }

  /**
   * Dry runs return deduplicated required-field warnings without writing state.
   */
  public function testDryRunWarningsWithoutWrites(): void {
    $fieldStorage = $this->createMock(FieldStorageDefinitionInterface::class);
    $fieldStorage->method('isBaseField')->willReturn(FALSE);
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getFieldStorageDefinition')->willReturn($fieldStorage);
    $field->method('getType')->willReturn('image');
    $field->method('isRequired')->willReturn(TRUE);
    $empty = $this->createMock(FieldItemListInterface::class);
    $empty->method('isEmpty')->willReturn(TRUE);
    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('getFieldDefinitions')->willReturn(['field_image' => $field]);
    $node->method('get')->willReturn($empty);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('bundle')->willReturn('page');
    $node->expects(self::never())->method('save');
    $nodes = $this->createMock(EntityStorageInterface::class);
    $nodes->method('create')->willReturn($node);
    $bundles = $this->createMock(EntityStorageInterface::class);
    $bundles->method('load')->willReturn($this->createMock(EntityInterface::class));
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('getStorage')->willReturnMap([['node', $nodes], ['node_type', $bundles]]);
    $state = [];
    $settings = ['enabled' => TRUE, 'profiles.default' => ['bundle' => 'page'], 'safeguards.blocked_envs' => []];
    $manager = $this->manager($types, $state, $settings);
    $result = $manager->seed('default', ['count' => 3, 'dry_run' => TRUE]);
    self::assertCount(1, $result['warnings']);
    self::assertStringContainsString('node.page.field_image', $result['warnings'][0]);
    self::assertSame([], $state);
    self::assertSame(0, $result['stats']['node']);
  }

  /**
   * An error on a later node leaves earlier IDs recoverable by reset.
   */
  public function testFailedRunCanBeReset(): void {
    $state = [];
    $first = $this->createMock(ContentEntityInterface::class);
    $first->method('id')->willReturn(42);
    $first->method('getFieldDefinitions')->willReturn([]);
    $second = $this->createMock(ContentEntityInterface::class);
    $second->method('getFieldDefinitions')->willReturn([]);
    $second->method('save')->willReturnCallback(static function () use (&$state): never {
      $id = $state['drupal_mock_data_seeder.last_run_id'];
      self::assertSame([42], $state['drupal_mock_data_seeder.runs.' . $id]['created']['node']);
      throw new \RuntimeException('Simulated storage failure');
    });
    $nodes = $this->createMock(EntityStorageInterface::class);
    $nodes->method('create')->willReturnOnConsecutiveCalls($first, $second);
    $nodes->method('loadMultiple')->with([42])->willReturn([$first]);
    $nodes->expects(self::once())->method('delete')->with([$first]);
    $bundles = $this->createMock(EntityStorageInterface::class);
    $bundles->method('load')->willReturn($this->createMock(EntityInterface::class));
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('getStorage')->willReturnMap([['node', $nodes], ['node_type', $bundles]]);
    $types->method('hasDefinition')->willReturnCallback(static fn($type) => $type === 'node');
    $settings = ['enabled' => TRUE, 'profiles.default' => ['bundle' => 'article'], 'safeguards.blocked_envs' => []];
    $manager = $this->manager($types, $state, $settings);
    try {
      $manager->seed('default', ['count' => 2]);
      self::fail('Expected the second node to fail.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('mock:reset --run-id=', $exception->getMessage());
    }
    $id = $state['drupal_mock_data_seeder.last_run_id'];
    self::assertSame('failed', $state['drupal_mock_data_seeder.runs.' . $id]['status']);
    self::assertSame(1, $manager->reset($id)['deleted']['node']);
    self::assertSame([], $state);
  }

  /**
   * Related entities remain recoverable even if their parent is never saved.
   */
  public function testChildIsJournaledBeforeParentSave(): void {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getType')->willReturn('entity_reference');
    $field->method('getSettings')->willReturn(['target_type' => 'taxonomy_term']);
    $fieldStorage = $this->createMock(FieldStorageDefinitionInterface::class);
    $fieldStorage->method('isBaseField')->willReturn(TRUE);
    $field->method('getFieldStorageDefinition')->willReturn($fieldStorage);
    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('getFieldDefinitions')->willReturn(['field_tags' => $field]);
    $node->expects(self::never())->method('save');
    $nodes = $this->createMock(EntityStorageInterface::class);
    $nodes->method('create')->willReturn($node);
    $term = $this->createMock(EntityInterface::class);
    $term->method('id')->willReturn(99);
    $badTerm = $this->createMock(EntityInterface::class);
    $badTerm->method('save')->willThrowException(new \RuntimeException('Term save failed'));
    $terms = $this->createMock(EntityStorageInterface::class);
    $terms->method('loadByProperties')->willReturn([]);
    $terms->method('create')->willReturnOnConsecutiveCalls($term, $badTerm);
    $terms->method('loadMultiple')->with([99])->willReturn([$term]);
    $terms->expects(self::once())->method('delete')->with([$term]);
    $bundles = $this->createMock(EntityStorageInterface::class);
    $bundles->method('load')->willReturn($this->createMock(EntityInterface::class));
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('getStorage')->willReturnMap([['node', $nodes], ['node_type', $bundles], ['taxonomy_term', $terms]]);
    $types->method('hasDefinition')->willReturn(TRUE);
    $state = [];
    $settings = [
      'enabled' => TRUE,
      'safeguards.blocked_envs' => [],
      'profiles.default' => [
        'bundle' => 'article',
        'taxonomy' => ['terms_min' => 2, 'terms_max' => 2, 'create_if_missing' => TRUE],
      ],
    ];
    $manager = $this->manager($types, $state, $settings);
    try {
      $manager->seed('default', ['count' => 1]);
      self::fail('Expected term creation to fail.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('Term save failed', $exception->getMessage());
    }
    $id = $state['drupal_mock_data_seeder.last_run_id'];
    self::assertSame([99], $state['drupal_mock_data_seeder.runs.' . $id]['created']['taxonomy_term']);
    self::assertSame([], $state['drupal_mock_data_seeder.runs.' . $id]['created']['node']);
    self::assertSame(1, $manager->reset($id)['deleted']['taxonomy_term']);
  }

}
