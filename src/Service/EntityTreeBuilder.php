<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\node\NodeInterface;
use Faker\Generator;

final class EntityTreeBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FieldValueGenerator $fieldValueGenerator,
  ) {}

  /**
   * Builds one node tree and returns entity IDs grouped by entity type.
   */
  public function buildNodeTree(string $bundle, array $profile, int $depth, Generator $faker, bool $dryRun = FALSE): array {
    $created = [
      'node' => [],
      'paragraph' => [],
      'taxonomy_term' => [],
      'media' => [],
    ];

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $node = $nodeStorage->create([
      'type' => $bundle,
      'title' => $this->fieldValueGenerator->nodeTitle($faker),
      'status' => 1,
    ]);

    if ($node->hasField('body')) {
      $node->set('body', [
        'value' => $this->fieldValueGenerator->longText($faker),
        'format' => 'basic_html',
      ]);
    }

    $this->attachTaxonomyReferences($node, $profile, $faker, $created, $dryRun);
    $this->attachMediaReferences($node, $profile, $faker, $created, $dryRun);
    $this->attachParagraphs($node, $profile, $depth, $faker, $created, $dryRun);

    if (!$dryRun) {
      $node->save();
      $created['node'][] = (int) $node->id();
    }

    return $created;
  }

  private function attachTaxonomyReferences(NodeInterface $node, array $profile, Generator $faker, array &$created, bool $dryRun): void {
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return;
    }

    $fields = $this->findReferenceFields($node, 'taxonomy_term');
    if ($fields === []) {
      return;
    }

    $taxonomyProfile = $profile['taxonomy'] ?? [];
    $min = (int) ($taxonomyProfile['terms_min'] ?? 1);
    $max = (int) ($taxonomyProfile['terms_max'] ?? 3);
    $count = mt_rand(max(1, $min), max($min, $max));

    foreach ($fields as $fieldName => $definition) {
      $vocabulary = $this->resolveVocabulary($definition, (string) ($taxonomyProfile['vocabulary'] ?? 'tags'));
      $termIds = $this->ensureTaxonomyTerms($vocabulary, $count, !empty($taxonomyProfile['create_if_missing']), $faker, $created, $dryRun);
      if ($termIds === []) {
        continue;
      }

      shuffle($termIds);
      $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
      $limit = $cardinality > 0 ? min($cardinality, $count) : $count;
      $selected = array_slice($termIds, 0, $limit);
      $node->set($fieldName, array_map(static fn(int $id) => ['target_id' => $id], $selected));
    }
  }

  private function attachMediaReferences(NodeInterface $node, array $profile, Generator $faker, array &$created, bool $dryRun): void {
    if (!$this->entityTypeManager->hasDefinition('media')) {
      return;
    }

    $fields = $this->findReferenceFields($node, 'media');
    if ($fields === []) {
      return;
    }

    $mediaProfile = $profile['media'] ?? [];
    $min = (int) ($mediaProfile['min'] ?? 1);
    $max = (int) ($mediaProfile['max'] ?? 2);
    $count = mt_rand(max(1, $min), max($min, $max));

    $mediaIds = $this->ensureMediaIds($count, !empty($mediaProfile['create_if_missing']), (string) ($mediaProfile['bundle'] ?? ''), $faker, $created, $dryRun);
    if ($mediaIds === []) {
      return;
    }

    foreach ($fields as $fieldName => $definition) {
      shuffle($mediaIds);
      $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
      $limit = $cardinality > 0 ? min($cardinality, $count) : $count;
      $selected = array_slice($mediaIds, 0, $limit);
      $node->set($fieldName, array_map(static fn(int $id) => ['target_id' => $id], $selected));
    }
  }

  private function ensureTaxonomyTerms(string $vocabulary, int $minimumCount, bool $createIfMissing, Generator $faker, array &$created, bool $dryRun): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $termStorage->loadByProperties(['vid' => $vocabulary]);
    $termIds = array_map(static fn($term) => (int) $term->id(), $existing);

    if (count($termIds) < $minimumCount && $createIfMissing && !$dryRun) {
      for ($i = count($termIds); $i < $minimumCount; $i++) {
        $term = $termStorage->create([
          'vid' => $vocabulary,
          'name' => $faker->words(mt_rand(1, 3), TRUE),
        ]);
        $term->save();
        $termIds[] = (int) $term->id();
        $created['taxonomy_term'][] = (int) $term->id();
      }
    }

    return $termIds;
  }

  private function ensureMediaIds(int $minimumCount, bool $createIfMissing, string $preferredBundle, Generator $faker, array &$created, bool $dryRun): array {
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $conditions = $preferredBundle !== '' ? ['bundle' => $preferredBundle] : [];
    $existing = $mediaStorage->loadByProperties($conditions);
    $mediaIds = array_map(static fn($media) => (int) $media->id(), $existing);

    if (count($mediaIds) < $minimumCount && $createIfMissing && !$dryRun) {
      for ($i = count($mediaIds); $i < $minimumCount; $i++) {
        $media = $this->createRemoteVideoMedia($preferredBundle, $faker);
        if ($media === NULL) {
          break;
        }
        $mediaIds[] = (int) $media->id();
        $created['media'][] = (int) $media->id();
      }
    }

    return $mediaIds;
  }

  private function createRemoteVideoMedia(string $preferredBundle, Generator $faker): ?ContentEntityInterface {
    if (!$this->entityTypeManager->hasDefinition('media_type')) {
      return NULL;
    }

    $mediaTypeStorage = $this->entityTypeManager->getStorage('media_type');
    $types = $mediaTypeStorage->loadMultiple();
    if ($types === []) {
      return NULL;
    }

    $candidates = [];
    if ($preferredBundle !== '' && isset($types[$preferredBundle])) {
      $candidates[$preferredBundle] = $types[$preferredBundle];
    }
    foreach ($types as $id => $type) {
      if (!isset($candidates[$id])) {
        $candidates[$id] = $type;
      }
    }

    $mediaStorage = $this->entityTypeManager->getStorage('media');
    foreach ($candidates as $bundle => $mediaType) {
      if (!method_exists($mediaType, 'getSourceConfiguration')) {
        continue;
      }

      $sourceConfig = $mediaType->getSourceConfiguration();
      $sourceField = (string) ($sourceConfig['source_field'] ?? '');
      if ($sourceField === '') {
        continue;
      }

      $media = $mediaStorage->create([
        'bundle' => $bundle,
        'name' => $faker->sentence(4),
      ]);
      if (!$media->hasField($sourceField)) {
        continue;
      }

      $fieldType = $media->getFieldDefinition($sourceField)->getType();
      $videoUrl = $faker->randomElement([
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://vimeo.com/76979871',
      ]);

      if ($fieldType === 'link') {
        $media->set($sourceField, ['uri' => $videoUrl]);
      }
      elseif (in_array($fieldType, ['string', 'string_long', 'uri'], TRUE)) {
        $media->set($sourceField, $videoUrl);
      }
      else {
        continue;
      }

      $media->save();
      return $media;
    }

    return NULL;
  }

  /**
   * Returns field definitions for entity reference fields targeting one entity type.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Indexed by field machine name.
   */
  private function findReferenceFields(ContentEntityInterface $entity, string $targetType): array {
    $matches = [];
    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if ($definition->getType() !== 'entity_reference') {
        continue;
      }
      $settings = $definition->getSettings();
      if (($settings['target_type'] ?? '') === $targetType) {
        $matches[$fieldName] = $definition;
      }
    }

    return $matches;
  }

  private function resolveVocabulary(FieldDefinitionInterface $definition, string $fallback): string {
    $settings = $definition->getSetting('handler_settings');
    if (is_array($settings) && !empty($settings['target_bundles']) && is_array($settings['target_bundles'])) {
      $bundles = array_values(array_filter($settings['target_bundles'], static fn($value) => is_string($value) && $value !== ''));
      if ($bundles !== []) {
        return (string) $bundles[0];
      }
    }

    return $fallback;
  }

  private function attachParagraphs(NodeInterface $node, array $profile, int $depth, Generator $faker, array &$created, bool $dryRun): void {
    if ($depth < 1 || !class_exists('Drupal\\paragraphs\\Entity\\Paragraph')) {
      return;
    }

    $fieldName = $this->findParagraphField($node);
    if ($fieldName === NULL) {
      return;
    }

    $range = $profile['paragraphs_per_node'] ?? ['min' => 1, 'max' => 3];
    $paragraphCount = mt_rand((int) ($range['min'] ?? 1), (int) ($range['max'] ?? 3));

    $paragraphs = [];
    for ($i = 0; $i < $paragraphCount; $i++) {
      $paragraph = $this->createParagraphRecursive($profile, $depth, $faker, $created, $dryRun);
      if ($paragraph !== NULL) {
        $paragraphs[] = ['entity' => $paragraph];
      }
    }

    if ($paragraphs !== []) {
      $node->set($fieldName, $paragraphs);
    }
  }

  private function createParagraphRecursive(array $profile, int $depth, Generator $faker, array &$created, bool $dryRun): ?object {
    if ($depth < 1 || !class_exists('Drupal\\paragraphs\\Entity\\Paragraph')) {
      return NULL;
    }

    $types = $profile['paragraph_types'] ?? ['text_block'];
    $type = $types[array_rand($types)];

    $paragraph = \Drupal\paragraphs\Entity\Paragraph::create(['type' => $type]);

    if ($paragraph->hasField('field_title')) {
      $paragraph->set('field_title', $this->fieldValueGenerator->shortText($faker));
    }
    if ($paragraph->hasField('field_text')) {
      $paragraph->set('field_text', [
        'value' => $this->fieldValueGenerator->longText($faker),
        'format' => 'basic_html',
      ]);
    }

    $nestedField = $this->findParagraphField($paragraph);
    if ($nestedField !== NULL && $depth > 1 && mt_rand(0, 100) < 35) {
      $child = $this->createParagraphRecursive($profile, $depth - 1, $faker, $created, $dryRun);
      if ($child !== NULL) {
        $paragraph->set($nestedField, [['entity' => $child]]);
      }
    }

    if (!$dryRun) {
      $paragraph->save();
      $created['paragraph'][] = (int) $paragraph->id();
    }

    return $paragraph;
  }

  private function findParagraphField(object $entity): ?string {
    if (!method_exists($entity, 'getFieldDefinitions')) {
      return NULL;
    }

    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }
      $settings = $definition->getSettings();
      if (($settings['target_type'] ?? '') === 'paragraph') {
        return $fieldName;
      }
    }

    return NULL;
  }

}

