<?php

namespace Drupal\layoutgenentitystyles\Services;

use Stephane888\Debug\Repositories\ConfigDrupal;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\generate_style_theme\Entity\ConfigThemeEntity;
use Drupal\generate_style_theme\Services\GenerateStyleTheme;

class BuildStylesByEntities extends BuilderStylesBase {
  
  /**
   * Permet de parcourir les entites qui peuvent avoir les styles.
   */
  protected function entitiesRoutes() {
    $configs = ConfigDrupal::config('layoutgenentitystyles.settings');
    if (!empty($configs['entities_pages'])) {
      foreach (array_keys($configs['entities_pages']) as $entity_type_id) {
        $typeEntities = $this->entityTypeManager()->getStorage($entity_type_id)->loadMultiple();
        foreach ($typeEntities as $bundle => $entityType) {
          /**
           * Les styles pour le bundle ou l'entite
           *
           * @var array $styles
           */
          $styles = [];
          /**
           *
           * @var \Drupal\blockscontent\Entity\BlocksContentsType $entityType
           */
          if ($entityType instanceof \Drupal\Core\Config\Entity\ConfigEntityBundleBase) {
            $filename = $entityType->getEntityType()->getBundleOf() . '__' . $bundle;
            $sectionStoragesViews = $this->loadEntityViewDisplay($bundle, $entityType->getEntityType()->getBundleOf());
            foreach ($sectionStoragesViews as $sectionStoragesView) {
              $this->generateSTyleFromEntity($sectionStoragesView, $styles);
            }
          }
          if ($styles && $filename) {
            $this->generateFilesStyles($styles, $filename);
          }
        }
      }
    }
    else {
      $this->messenger()->addWarning('Vous devez specifier les entities qui peuvent porter les styles');
    }
  }
  
  /**
   * Genere les styles pour le mode d'affichage.
   *
   * @param LayoutBuilderEntityViewDisplay $entity
   */
  function generateSTyleFromEntity(LayoutBuilderEntityViewDisplay $entityView, array &$styles) {
    $layout_builder = $this->getSectionsForEntityView($entityView);
    $sections = $layout_builder['sections'] ?? [];
    if ($sections) {
      $styles[$entityView->id()] = $this->getLibraryForEachSections($sections);
    }
  }
  
  protected function loadEntityViewDisplay($bundle, $entity_type_id) {
    $sectionStoragesViews = $this->entityTypeManager()->getStorage('entity_view_display')->loadByProperties([
      'bundle' => $bundle,
      'targetEntityType' => $entity_type_id
    ]);
    return $sectionStoragesViews;
  }
  
  /**
   * Genrere directement les fichiers scss et js.
   */
  protected function generateFilesStyles(array $styles, string $filename) {
    $defaultThemeName = $this->getDefaultTheme();
    if (!empty($defaultThemeName)) {
      $arrayStyles = $this->getArrayScssJs($styles);
      $ids = $this->entityTypeManager()->getStorage('config_theme_entity')->getQuery()->condition('hostname', $defaultThemeName)->accessCheck(false)->execute();
      if (!empty($ids)) {
        $entity = ConfigThemeEntity::load(reset($ids));
        $GenerateStyleTheme = new GenerateStyleTheme($entity);
        $GenerateStyleTheme->buildCustomScssFromArray($arrayStyles['scss'], $filename);
        $GenerateStyleTheme->buildCustomJsFromArray($arrayStyles['js'], $filename);
      }
    }
    if ($this->shoMessage)
      $this->messenger()->addStatus(" Vous devez regenerer votre theme ");
  }
  
  /**
   *
   * @param array $styles
   */
  protected function getArrayScssJs(array $styles) {
    $scss = [];
    $js = [];
    foreach ($styles as $key => $style) {
      [
        $entity_id,
        $bundle,
        $mode
      ] = explode(".", $key);
      $scss[$entity_id][$bundle][$mode] = $style['scss'];
      $js[$entity_id][$bundle][$mode] = $style['js'];
    }
    return [
      'scss' => $scss,
      'js' => $js
    ];
  }
  
  /**
   * Permet de generer tous les styles et de les ajouter dans la configuration
   * du theme actif.
   */
  function generateAllFilesStyles() {
    $ModuleConf = $this->getConfigFOR_generate_style_theme();
    // On construit les styles en fonction des entités.
    if (!empty($ModuleConf['tab1']) && $ModuleConf['tab1']['save_multifile'] == 1) {
      $this->entitiesRoutes();
    }
  }
  
}