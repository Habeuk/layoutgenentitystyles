<?php

namespace Drupal\layoutgenentitystyles\Services;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Service pour charger les paragraphes groupés par type de nœud.
 */
class ParagraphLoader {
  protected $entityTypeManager;
  protected $entityFieldManager;
  
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
  }
  
  /**
   * Trouve tous les champs de référence à des paragraphes.
   */
  public function findParagraphReferenceFields(array $entities) {
    $paragraph_fields = [];
    $field_map = $this->entityFieldManager->getFieldMap();
    foreach ($entities as $entity_type_id) {
      foreach ($field_map[$entity_type_id] as $field_name => $field_info) {
        $storage = FieldStorageConfig::loadByName($entity_type_id, $field_name);
        if ($storage && $storage->getSetting('target_type') === 'paragraph') {
          $paragraph_fields[$entity_type_id][$field_name] = $field_name;
        }
      }
    }
    return $paragraph_fields;
  }
  
  /**
   * Le but de cette function est de renvoyer les types de paragraphes qui ont
   * au moins 1 donnée pour les differentes entites fournit..
   */
  public function loadGroupedByNodeType(array $entities) {
    $EntitiesParagraph_fields = $this->findParagraphReferenceFields($entities);
    $grouped = [];
    foreach ($EntitiesParagraph_fields as $entity_type_id => $paragraph_fields) {
      $grouped[$entity_type_id] = [];
      if (empty($paragraph_fields))
        continue;
      // Construire une requête efficace
      $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()->accessCheck(FALSE);
      // Ajouter une condition OR pour tous les champs de paragraphes
      $or_group = $query->orConditionGroup();
      foreach ($paragraph_fields as $field) {
        $or_group->exists($field);
      }
      $query->condition($or_group);
      $ids = $query->execute();
      if (empty($ids))
        continue;
      //
      dd($ids);
      // Charger les nœuds avec leurs références de paragraphes
      $entities = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids);
      foreach ($entities as $entity) {
        $node_type = $entity->bundle();
        
        if (!isset($grouped[$node_type])) {
          $grouped[$node_type] = [];
        }
        
        // Vérifier tous les champs possibles
        foreach ($paragraph_fields as $field) {
          if ($entity->hasField($field)) {
            foreach ($entity->get($field) as $item) {
              if ($paragraph = $item->entity) {
                $grouped[$node_type][] = [
                  'paragraph' => $paragraph,
                  'node' => $entity,
                  'field_name' => $field,
                  'paragraph_type' => $paragraph->bundle()
                ];
              }
            }
          }
        }
      }
    }
    
    return $grouped;
  }
  
  /**
   * Implémentation avec cache pour de meilleures performances.
   */
  public function getCachedParagraphsByNodeType() {
    $cache = \Drupal::cache();
    $cid = 'paragraphs_by_node_type';
    
    if ($cache_data = $cache->get($cid)) {
      return $cache_data->data;
    }
    
    $data = $this->loadGroupedByNodeType();
    $cache->set($cid, $data, \Drupal::time()->getRequestTime() + 3600);
    
    return $data;
  }
}