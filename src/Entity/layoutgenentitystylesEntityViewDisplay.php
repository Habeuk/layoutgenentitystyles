<?php

namespace Drupal\layoutgenentitystyles\Entity;

use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Provides an entity view display entity that has a layout.
 */
class layoutgenentitystylesEntityViewDisplay extends LayoutBuilderEntityViewDisplay {
  
  /**
   *
   * {@inheritdoc}
   */
  public function __construct(array $values, $entity_type) {
    parent::__construct($values, $entity_type);
  }
  
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    \Drupal::messenger()->addStatus(" layoutgenentitystylesEntityViewDisplay preSave ");
  }
  
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);
    \Drupal::messenger()->addStatus(" layoutgenentitystylesEntityViewDisplay postSave ");
  }
  
}