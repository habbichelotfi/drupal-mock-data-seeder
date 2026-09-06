<?php

declare(strict_types=1);

namespace Drupal\drupal_mock_data_seeder\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Faker\Generator;

/**
 * Builds content trees and related entity references for one seed run.
 */
final class EntityTreeBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FieldValueGenerator $fieldValueGenerator,
  ) {}

  /**
   * Builds one node tree and returns entity IDs grouped by entity type.
   */
  public function buildNodeTree(string $bundle, array $profile, int $depth, Generator $faker, bool $dryRun = FALSE, ?callable $onCreated = NULL, ?callable $onWarning = NULL): array {
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
    if (!$node instanceof ContentEntityInterface) {
      throw new \RuntimeException('Unable to create a fieldable node entity.');
    }

    if ($node->hasField('body')) {
      $node->set('body', [
        'value' => $this->fieldValueGenerator->longText($faker),
        'format' => 'basic_html',
      ]);
    }

    $this->fieldValueGenerator->populateFields($node, $faker);

    $this->attachTaxonomyReferences($node, $profile, $faker, $created, $dryRun, $onCreated);
    $this->attachMediaReferences($node, $profile, $faker, $created, $dryRun, $onCreated);
    $this->attachParagraphs($node, $profile, $depth, $faker, $created, $dryRun, $onCreated, $onWarning);
    $this->reportRequiredFields($node, $onWarning);

    if (!$dryRun) {
      $node->save();
      $created['node'][] = (int) $node->id();
      if ($onCreated !== NULL) {
        $onCreated('node', (int) $node->id());
      }
    }

    return $created;
  }

  /**
   * Attaches taxonomy references on reference fields targeting taxonomy terms.
   */
  private function attachTaxonomyReferences(ContentEntityInterface $node, array $profile, Generator $faker, array &$created, bool $dryRun, ?callable $onCreated): void {
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
      $termIds = $this->ensureTaxonomyTerms($vocabulary, $count, !empty($taxonomyProfile['create_if_missing']), $faker, $created, $dryRun, $onCreated);
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

  /**
   * Attaches media references on reference fields targeting media entities.
   */
  private function attachMediaReferences(ContentEntityInterface $node, array $profile, Generator $faker, array &$created, bool $dryRun, ?callable $onCreated): void {
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

    $mediaIds = $this->ensureMediaIds($count, !empty($mediaProfile['create_if_missing']), (string) ($mediaProfile['bundle'] ?? ''), $faker, $created, $dryRun, $onCreated);
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

  /**
   * Loads or creates taxonomy terms to satisfy minimum reference count.
   */
  private function ensureTaxonomyTerms(string $vocabulary, int $minimumCount, bool $createIfMissing, Generator $faker, array &$created, bool $dryRun, ?callable $onCreated): array {
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
        if ($onCreated !== NULL) {
          $onCreated('taxonomy_term', (int) $term->id());
        }
      }
    }

    return $termIds;
  }

  /**
   * Loads or creates media entities to satisfy minimum reference count.
   */
  private function ensureMediaIds(int $minimumCount, bool $createIfMissing, string $preferredBundle, Generator $faker, array &$created, bool $dryRun, ?callable $onCreated): array {
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
        if ($onCreated !== NULL) {
          $onCreated('media', (int) $media->id());
        }
        $mediaIds[] = (int) $media->id();
        $created['media'][] = (int) $media->id();
      }
    }

    return $mediaIds;
  }

  /**
   * Creates one media entity compatible with a usable source field.
   */
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
      if (!$media instanceof ContentEntityInterface) {
        continue;
      }
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
   * Returns field definitions for entity reference fields of one target type.
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

  /**
   * Resolves a taxonomy vocabulary from field handler settings.
   */
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

  /**
   * Fills every writable Paragraph reference field within its own constraints.
   */
  private function attachParagraphs(ContentEntityInterface $entity, array $profile, int $depth, Generator $faker, array &$created, bool $dryRun, ?callable $onCreated, ?callable $onWarning, bool $nested = FALSE): void {
    if (!$this->entityTypeManager->hasDefinition('paragraph')) {
      return;
    }

    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions' || $definition->getSetting('target_type') !== 'paragraph' || $definition->isComputed() || $definition->isReadOnly()) {
        continue;
      }
      if (!$entity->get($fieldName)->isEmpty()) {
        continue;
      }
      if ($depth < 1) {
        continue;
      }
      // Optional nested fields retain the original 35% branching probability.
      if ($nested && !$definition->isRequired() && mt_rand(0, 99) >= 35) {
        continue;
      }
      $types = $this->paragraphTypes($definition, $profile);
      if ($types === []) {
        if ($onWarning !== NULL) {
          $onWarning(sprintf('%s.%s.%s: no existing Paragraph type matches the field and profile restrictions.', $entity->getEntityTypeId(), $entity->bundle(), $fieldName));
        }
        continue;
      }
      $range = $nested ? ['min' => 1, 'max' => 1] : ($profile['paragraphs_per_node'] ?? ['min' => 1, 'max' => 3]);
      $min = max($definition->isRequired() ? 1 : 0, (int) ($range['min'] ?? 1));
      $max = max($min, (int) ($range['max'] ?? 3));
      $cardinality = $definition->getFieldStorageDefinition()->getCardinality();
      if ($cardinality > 0) {
        $min = min($min, $cardinality);
        $max = min($max, $cardinality);
      }
      $count = mt_rand($min, $max);
      $items = [];
      for ($i = 0; $i < $count; $i++) {
        $type = $faker->randomElement($types);
        $paragraph = $this->entityTypeManager->getStorage('paragraph')->create(['type' => $type]);
        if (!$paragraph instanceof ContentEntityInterface) {
          throw new \RuntimeException('Unable to create a fieldable Paragraph entity.');
        }
        if ($paragraph->hasField('field_title')) {
          $paragraph->set('field_title', $this->fieldValueGenerator->shortText($faker));
        }
        if ($paragraph->hasField('field_text')) {
          $paragraph->set('field_text', [
            'value' => $this->fieldValueGenerator->longText($faker),
            'format' => 'basic_html',
          ]);
        }
        $this->fieldValueGenerator->populateFields($paragraph, $faker);
        $this->attachParagraphs($paragraph, $profile, $depth - 1, $faker, $created, $dryRun, $onCreated, $onWarning, TRUE);
        $this->reportRequiredFields($paragraph, $onWarning);
        if (!$dryRun) {
          $paragraph->save();
          $created['paragraph'][] = (int) $paragraph->id();
          if ($onCreated !== NULL) {
            $onCreated('paragraph', (int) $paragraph->id());
          }
        }
        $items[] = ['entity' => $paragraph];
      }
      if ($items !== []) {
        $entity->set($fieldName, $items);
      }
    }
  }

  /**
   * Intersects installed types, field restrictions and the profile list.
   */
  private function paragraphTypes(FieldDefinitionInterface $definition, array $profile): array {
    $types = array_keys($this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple());
    $settings = (array) $definition->getSetting('handler_settings');
    $targets = $settings['target_bundles'] ?? NULL;
    if (is_array($targets)) {
      $types = !empty($settings['negate'])
        ? array_diff($types, $targets)
        : array_intersect($types, $targets);
    }
    $profileTypes = (array) ($profile['paragraph_types'] ?? []);
    if ($profileTypes !== []) {
      $types = array_intersect($types, $profileTypes);
    }
    sort($types);
    return array_values($types);
  }

  /**
   * Reports empty required configurable fields, without full validation.
   */
  private function reportRequiredFields(ContentEntityInterface $entity, ?callable $onWarning): void {
    if ($onWarning === NULL) {
      return;
    }
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getFieldStorageDefinition()->isBaseField() || $definition->isComputed() || $definition->isReadOnly() || !$definition->isRequired()) {
        continue;
      }
      if ($entity->get($name)->isEmpty()) {
        $onWarning(sprintf('%s.%s.%s: required field is empty after generation (unsupported field, unavailable reference, or depth limit).', $entity->getEntityTypeId(), $entity->bundle(), $name));
      }
    }
  }

}
