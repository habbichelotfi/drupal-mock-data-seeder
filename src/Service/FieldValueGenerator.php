<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Faker\Generator;

/**
 * Generates text values used while seeding content.
 */
final class FieldValueGenerator {

  /**
   * Fills supported, empty configurable fields without changing base fields.
   */
  public function populateFields(ContentEntityInterface $entity, Generator $faker): void {
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getFieldStorageDefinition()->isBaseField() || $definition->isComputed() || $definition->isReadOnly() || !$entity->get($name)->isEmpty()) {
        continue;
      }
      $value = $this->fieldValue($definition, $faker);
      if ($value !== NULL) {
        $entity->set($name, $value);
      }
    }
  }

  /**
   * Generates one item; unsupported fields are left for specialized builders.
   */
  public function fieldValue(FieldDefinitionInterface $definition, Generator $faker): mixed {
    $type = $definition->getType();
    if (in_array($type, ['list_string', 'list_integer', 'list_float'], TRUE)) {
      // Dynamic allowed-value callbacks need entity context and are skipped.
      if ($definition->getSetting('allowed_values_function')) {
        return NULL;
      }
      $allowed = (array) $definition->getSetting('allowed_values');
      return $allowed === [] ? NULL : $faker->randomElement(array_keys($allowed));
    }
    if (in_array($type, ['integer', 'decimal', 'float'], TRUE)) {
      $min = $definition->getSetting('min') ?? min(0, (float) ($definition->getSetting('max') ?? 100));
      $max = $definition->getSetting('max') ?? max(100, (float) $min);
      if ($type === 'decimal') {
        $precision = (int) ($definition->getSetting('precision') ?? 10);
        $scale = (int) ($definition->getSetting('scale') ?? 2);
        $bound = 10 ** ($precision - $scale) - 10 ** (-$scale);
        $min = max((float) $min, -$bound);
        $max = min((float) $max, $bound);
      }
      if ($min > $max) {
        throw new \InvalidArgumentException('Invalid numeric field bounds: ' . $definition->getName());
      }
      return $type === 'integer'
        ? $faker->numberBetween((int) ceil((float) $min), (int) floor((float) $max))
        : $faker->randomFloat((int) ($definition->getSetting('scale') ?? 2), (float) $min, (float) $max);
    }
    return match ($type) {
      'string' => mb_substr($this->shortText($faker), 0, (int) ($definition->getSetting('max_length') ?? 255)),
      'string_long' => $this->longText($faker),
      'email' => $faker->safeEmail(),
      'telephone' => mb_substr($faker->phoneNumber(), 0, 32),
      'boolean' => (int) $faker->boolean(),
      'link' => [
        'uri' => ((int) $definition->getSetting('link_type') === 1) ? 'internal:/' : $faker->url(),
        'title' => $this->shortText($faker),
      ],
      'datetime' => $faker->dateTimeBetween('2020-01-01', '2030-01-01', 'UTC')->format($definition->getSetting('datetime_type') === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i:s'),
      'timestamp' => $faker->numberBetween(1577836800, 1893456000),
      default => NULL,
    };
  }

  /**
   * Builds a fake node title.
   */
  public function nodeTitle(Generator $faker): string {
    return $faker->sentence(6);
  }

  /**
   * Builds a longer fake paragraph/body content.
   */
  public function longText(Generator $faker): string {
    return $faker->paragraphs(mt_rand(2, 5), TRUE);
  }

  /**
   * Builds short fake text snippets.
   */
  public function shortText(Generator $faker): string {
    return $faker->sentence(12);
  }

}
