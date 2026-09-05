<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Service;

use Faker\Generator;

/**
 * Generates text values used while seeding content.
 */
final class FieldValueGenerator {

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
