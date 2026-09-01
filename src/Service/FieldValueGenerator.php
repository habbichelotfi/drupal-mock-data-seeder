<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Service;

use Faker\Generator;

final class FieldValueGenerator {

  public function nodeTitle(Generator $faker): string {
    return $faker->sentence(6);
  }

  public function longText(Generator $faker): string {
    return $faker->paragraphs(mt_rand(2, 5), TRUE);
  }

  public function shortText(Generator $faker): string {
    return $faker->sentence(12);
  }

}

