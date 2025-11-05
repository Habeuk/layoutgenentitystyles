<?php

namespace Drupal\layoutgenentitystyles\Services;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManager;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\generate_style_theme\Entity\ConfigThemeEntity;
use Drupal\generate_style_theme\Services\GenerateStyleTheme;
use Drupal\generate_style_theme\Services\ManageFileCustomStyle;
use Drupal\generate_style_theme\Services\ManageFileMailStyle;
use Drupal\Component\Utility\Timer;
use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\Section;

class BuildStylesByEntities extends BuilderStylesBase {
  
  /**
   * Permet de generer tous les styles et de les ajouter dans la configuration
   * du theme actif.
   */
  function generateAllFilesStyles() {
    $ModuleConf = $this->getConfigFOR_generate_style_theme();
    // On construit les styles en fonction des entités.
    if (!empty($ModuleConf['tab1']) && $ModuleConf['tab1']['save_multifile'] == 1) {
      // $this->test();
    }
  }
  
  function test() {
    // Get the current base field definitions.
    $base_fields = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('files_style');
    if (!isset($base_fields['route_name'])) {
      $field_name = 'route_name';
      $entity_type_id = 'files_style';
      $storageDef = \Drupal\Core\Field\BaseFieldDefinition::create('string')->setLabel(t('Route name'))->setDescription(t('Optional: the full or partial name of a route this style applies to.'))->setRevisionable(TRUE)->setRequired(FALSE)->setSetting('max_length', 255)->setDefaultValue(NULL);
      $updateManager = \Drupal::entityDefinitionUpdateManager();
      $updateManager->installFieldStorageDefinition($field_name, $entity_type_id, "generate_style_theme", $storageDef);
      return t('The "route_name" field has been added to the files_style entity.');
    }
  }
  
}