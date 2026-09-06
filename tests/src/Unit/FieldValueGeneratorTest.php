<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mock_data_seeder\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\drupal_mock_data_seeder\Service\FieldValueGenerator;
use Faker\Factory;
use PHPUnit\Framework\TestCase;

/**
 * Tests generated values against field configuration boundaries.
 *
 * @group drupal_mock_data_seeder
 */
final class FieldValueGeneratorTest extends TestCase {

  /**
   * Creates a configurable field definition.
   */
  private function field(string $type, array $settings = []): FieldDefinitionInterface {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getType')->willReturn($type);
    $field->method('getSetting')->willReturnCallback(static fn($key) => $settings[$key] ?? NULL);
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('isBaseField')->willReturn(FALSE);
    $field->method('getFieldStorageDefinition')->willReturn($storage);
    return $field;
  }

  /**
   * Respects numeric bounds, string lengths, allowed values and date storage.
   */
  public function testConfiguredValues(): void {
    $generator = new FieldValueGenerator();
    $faker = Factory::create('fr_FR');
    $faker->seed(4242);
    for ($i = 0; $i < 30; $i++) {
      self::assertLessThanOrEqual(4, mb_strlen($generator->fieldValue($this->field('string', ['max_length' => 4]), $faker)));
      self::assertSame(7, $generator->fieldValue($this->field('integer', ['min' => 7, 'max' => 7]), $faker));
      $negative = $generator->fieldValue($this->field('integer', ['max' => -5]), $faker);
      self::assertLessThanOrEqual(-5, $negative);
      $decimal = $generator->fieldValue($this->field('decimal', ['precision' => 2, 'scale' => 2]), $faker);
      self::assertGreaterThanOrEqual(0, $decimal);
      self::assertLessThanOrEqual(0.99, $decimal);
      $list = $this->field('list_string', ['allowed_values' => ['a' => 'A', 'b' => 'B']]);
      self::assertContains($generator->fieldValue($list, $faker), ['a', 'b']);
    }
    self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $generator->fieldValue($this->field('datetime', ['datetime_type' => 'date']), $faker));
    self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $generator->fieldValue($this->field('datetime'), $faker));
    self::assertNotFalse(filter_var($generator->fieldValue($this->field('email'), $faker), FILTER_VALIDATE_EMAIL));
    self::assertSame('internal:/', $generator->fieldValue($this->field('link', ['link_type' => 1]), $faker)['uri']);
    self::assertNull($generator->fieldValue($this->field('image'), $faker));
    self::assertNull($generator->fieldValue($this->field('list_string', ['allowed_values_function' => 'dynamic_values']), $faker));
  }

  /**
   * Preserves existing field values while filling empty configurable fields.
   */
  public function testExistingValuesArePreserved(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getFieldDefinitions')->willReturn([
      'field_existing' => $this->field('email'),
      'field_empty' => $this->field('boolean'),
    ]);
    $existing = $this->createMock(FieldItemListInterface::class);
    $existing->method('isEmpty')->willReturn(FALSE);
    $empty = $this->createMock(FieldItemListInterface::class);
    $empty->method('isEmpty')->willReturn(TRUE);
    $entity->method('get')->willReturnMap([['field_existing', $existing], ['field_empty', $empty]]);
    $entity->expects(self::once())->method('set')->with('field_empty', self::isType('int'));
    (new FieldValueGenerator())->populateFields($entity, Factory::create());
  }

}
