<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mock_data_seeder\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\drupal_mock_data_seeder\Service\EntityTreeBuilder;
use Drupal\drupal_mock_data_seeder\Service\FieldValueGenerator;
use Faker\Factory;
use PHPUnit\Framework\TestCase;

/**
 * Exercises Paragraph trees against field restrictions without static services.
 *
 * @group drupal_mock_data_seeder
 */
final class ParagraphGenerationTest extends TestCase {

  /**
   * Builds a configurable Paragraph reference field.
   */
  private function field(?array $targets = NULL, int $cardinality = 1, bool $required = FALSE, bool $negate = FALSE): FieldDefinitionInterface {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('getCardinality')->willReturn($cardinality);
    $storage->method('isBaseField')->willReturn(FALSE);
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getFieldStorageDefinition')->willReturn($storage);
    $field->method('getType')->willReturn('entity_reference_revisions');
    $field->method('isRequired')->willReturn($required);
    $field->method('getSetting')->willReturnMap([
      ['target_type', 'paragraph'],
      ['handler_settings', ['target_bundles' => $targets, 'negate' => $negate]],
    ]);
    return $field;
  }

  /**
   * Creates an entity whose field list reflects values assigned by the builder.
   */
  private function entity(string $type, string $bundle, array $fields, bool $dryRun, array &$records): ContentEntityInterface {
    $id = count($records) + 1;
    $record = (object) ['type' => $type, 'bundle' => $bundle, 'values' => []];
    $records[] = $record;
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('id')->willReturn($id);
    $entity->method('getEntityTypeId')->willReturn($type);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('getFieldDefinitions')->willReturn($fields);
    $entity->method('get')->willReturnCallback(function ($name) use ($record) {
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('isEmpty')->willReturnCallback(static fn() => empty($record->values[$name]));
      return $list;
    });
    $entity->method('set')->willReturnCallback(static function ($name, $value) use ($record, $entity) {
      $record->values[$name] = $value;
      return $entity;
    });
    $entity->expects($dryRun ? self::never() : self::once())->method('save');
    return $entity;
  }

  /**
   * Builds a real tree builder using in-memory entity storage.
   */
  private function builder(array $nodeFields, array $paragraphFields, bool $dryRun, array &$records): EntityTreeBuilder {
    $node = $this->entity('node', 'page', $nodeFields, $dryRun, $records);
    $nodes = $this->createMock(EntityStorageInterface::class);
    $nodes->method('create')->willReturn($node);
    $paragraphs = $this->createMock(EntityStorageInterface::class);
    $paragraphs->method('create')->willReturnCallback(function ($values) use ($paragraphFields, $dryRun, &$records) {
      return $this->entity('paragraph', $values['type'], $paragraphFields[$values['type']], $dryRun, $records);
    });
    $bundles = $this->createMock(EntityStorageInterface::class);
    $bundles->method('loadMultiple')->willReturn(array_fill_keys(array_keys($paragraphFields), NULL));
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('hasDefinition')->willReturnCallback(static fn($type) => $type === 'paragraph');
    $types->method('getStorage')->willReturnMap([
      ['node', $nodes],
      ['paragraph', $paragraphs],
      ['paragraphs_type', $bundles],
    ]);
    return new EntityTreeBuilder($types, new FieldValueGenerator());
  }

  /**
   * Each field uses its own allowed types and cardinality and is journaled.
   */
  public function testMultipleFieldsAndCardinality(): void {
    $records = [];
    $builder = $this->builder([
      'field_hero' => $this->field(['hero' => 'hero']),
      'field_content' => $this->field(['text' => 'text'], 2),
    ], ['hero' => [], 'text' => []], FALSE, $records);
    $journal = [];
    $result = $builder->buildNodeTree('page', ['paragraphs_per_node' => ['min' => 4, 'max' => 4]], 2, Factory::create(), FALSE, static function ($type, $id) use (&$journal): void {
      $journal[$type][] = $id;
    });
    self::assertSame(['page', 'hero', 'text', 'text'], array_column($records, 'bundle'));
    self::assertCount(1, $records[0]->values['field_hero']);
    self::assertCount(2, $records[0]->values['field_content']);
    self::assertSame($result['paragraph'], $journal['paragraph']);
    self::assertSame($result['node'], $journal['node']);
  }

  /**
   * Profile restrictions and negated field types are both honored.
   */
  public function testProfileIntersectionAndExclusion(): void {
    $records = [];
    $fields = ['field_content' => $this->field(['hero' => 'hero'], 1, FALSE, TRUE)];
    $builder = $this->builder($fields, ['hero' => [], 'text' => [], 'cta' => []], TRUE, $records);
    $builder->buildNodeTree('page', ['paragraph_types' => ['hero', 'text', 'missing']], 1, Factory::create(), TRUE);
    self::assertSame(['page', 'text'], array_column($records, 'bundle'));
  }

  /**
   * Empty allowed lists produce a useful warning and never fall back to a type.
   */
  public function testNoAllowedTypes(): void {
    $records = [];
    $builder = $this->builder(['field_content' => $this->field([], 1, TRUE)], ['text' => []], TRUE, $records);
    $warnings = [];
    $result = $builder->buildNodeTree('page', [], 2, Factory::create(), TRUE, NULL, static function ($message) use (&$warnings): void {
      $warnings[] = $message;
    });
    self::assertCount(1, $records);
    self::assertSame([], $result['paragraph']);
    self::assertCount(2, $warnings);
    self::assertStringContainsString('node.page.field_content', $warnings[0]);
    self::assertStringContainsString('required field is empty', $warnings[1]);
  }

  /**
   * Required nesting stops at the depth limit, including cycles.
   */
  public function testRequiredNestingStopsAtDepthWithoutSaving(): void {
    $records = [];
    $builder = $this->builder(['field_content' => $this->field(NULL, 1, TRUE)], ['section' => ['field_children' => $this->field(NULL, 1, TRUE)]], TRUE, $records);
    $warnings = [];
    $builder->buildNodeTree('page', [], 3, Factory::create(), TRUE, static function (): void {
      self::fail('A dry run must not journal entities.');
    }, static function ($message) use (&$warnings): void {
      $warnings[] = $message;
    });
    self::assertSame(['page', 'section', 'section', 'section'], array_column($records, 'bundle'));
    self::assertCount(1, $warnings);
    self::assertStringContainsString('paragraph.section.field_children', $warnings[0]);
  }

}
