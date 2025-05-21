<?php

namespace Drupal\layoutgenentitystyles\Services;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\EntityStorageInterface;

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
    foreach ($entities as $entity_type_id) {
      $fields = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);
      foreach ($fields as $field_name => $field_info) {
        /**
         *
         * @var \Drupal\Core\Field\BaseFieldDefinition $field_info
         */
        if ($field_info->getSetting('target_type') === 'paragraph') {
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
  public function loadGroupedByParagraphType(array $entities) {
    $EntitiesParagraph_fields = $this->findParagraphReferenceFields($entities);
    // dump($entities, $EntitiesParagraph_fields);
    $grouped = [];
    foreach ($EntitiesParagraph_fields as $entity_type_id => $paragraph_fields) {
      $grouped[$entity_type_id] = [];
      if (empty($paragraph_fields))
        continue;
      /**
       *
       * @var \Drupal\Core\Entity\EntityStorageInterface $EntityStorage
       */
      $EntityStorage = $this->entityTypeManager->getStorage($entity_type_id);
      $table = $EntityStorage->getEntityType()->getBaseTable();
      $id = $EntityStorage->getEntityType()->getKey('id');
      
      foreach ($paragraph_fields as $field) {
        $query = $this->updateQuery($table, $entity_type_id, $field, $id, $EntityStorage);
        $grouped[$entity_type_id] = array_merge($grouped[$entity_type_id], $query->execute()->fetchAll(\PDO::FETCH_ASSOC));
      }
    }
    return $grouped;
  }
  
  /**
   * Permet de construire la requete pour compte les entites en relations avec
   * les paragraphes.
   *
   * @param string $table
   * @return \Drupal\mysql\Driver\Database\mysql\Select
   */
  public function updateQuery(string $table, string $entity_type_id, string $field, mixed $id, EntityStorageInterface $EntityStorage) {
    /**
     *
     * @var \Drupal\Core\Database\Connection $connexion
     */
    $connexion = \Drupal::database();
    /**
     *
     * @var \Drupal\mysql\Driver\Database\mysql\Select $query
     */
    $query = $connexion->select($table, $table)->fields($table);
    //
    $tableJoin = $entity_type_id . '__' . $field;
    $condition = $tableJoin . '.entity_id = ' . $table . '.' . $id;
    $query->addJoin('INNER', $tableJoin, $tableJoin, $condition);
    //
    $tableJoin2 = 'paragraphs_item_field_data';
    $condition2 = $tableJoin2 . '.id = ' . $table . '.' . $id;
    $query->addJoin('INNER', $tableJoin2, $tableJoin2, $condition2);
    $query->addField($tableJoin2, 'type', 'paragraph_type');
    //
    $query->groupBy($tableJoin2 . '.type');
    $query->groupBy($table . '.type');
    return $query;
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