<?php

namespace Drupal\layoutgenentitystyles\Services;

use Stephane888\Debug\Repositories\ConfigDrupal;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\generate_style_theme\Entity\ConfigThemeEntity;
use Drupal\generate_style_theme\Services\GenerateStyleTheme;
use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\Section;
use Drupal\Core\Entity\ContentEntityBase;

class BuildStylesByEntities extends BuilderStylesBase {
  /**
   * Contient les routes qui peuvent avoir les styles.
   *
   * @var array
   */
  protected $routes = [];
  
  /**
   * Contient les library regouper par entité ou type d'entité.
   *
   * @var array
   */
  protected array $librariesByEntity = [];
  /**
   * Contient les styles definis de maniere customs.
   *
   * @var array
   */
  protected array $customsStyleByEntity = [];
  
  /**
   * Permet de parcourir les entites qui peuvent avoir les styles.
   */
  protected function entitiesGenerateDefautlStyles() {
    $configs = $this->getConfigs();
    if (!empty($configs['entities_pages'])) {
      foreach (array_keys($configs['entities_pages']) as $entity_type_id) {
        $typeEntities = $this->entityTypeManager()->getStorage($entity_type_id)->loadMultiple();
        foreach ($typeEntities as $bundle => $entityConfigType) {
          
          /**
           *
           * @var \Drupal\blockscontent\Entity\BlocksContentsType $entityType
           */
          if ($entityConfigType instanceof \Drupal\Core\Config\Entity\ConfigEntityBundleBase) {
            $entityType = $entityConfigType->getEntityType();
            $BundleOf = $entityType->getBundleOf();
            $filename = $BundleOf . '__' . $bundle;
            $DefaultStyle = [];
            $customStyle = [];
            $this->generateStyleFromDefautlEntity($bundle, $BundleOf, $DefaultStyle, $customStyle);
            if ($DefaultStyle && $filename) {
              $this->librariesByEntity[$filename] = $DefaultStyle;
              $this->customsStyleByEntity[$filename] = $customStyle;
              $this->routes['entity.' . $BundleOf . '.canonical.' . $bundle . '.default'] = $filename;
            }
            // Charge les styles surchargés.
            $sectionStoragesViews = $this->loadEntityViewDisplay($bundle, $BundleOf);
            foreach ($sectionStoragesViews as $sectionStoragesView) {
              $this->generateOverrideStyleFromEntity($sectionStoragesView);
            }
          }
        }
      }
      // dd($this->librariesByEntity, $this->routes);
      $this->generateFilesStyles();
      $this->saveRoutesInThemes();
    }
    else {
      $this->messenger()->addWarning("Vous devez specifier les entities qui peuvent porter les styles");
    }
  }
  
  protected function generateStyleFromDefautlEntity($bundle, $entityTypeId, &$DefaultStyle = [], &$customStyle = []) {
    $sectionStoragesViews = $this->loadEntityViewDisplay($bundle, $entityTypeId);
    foreach ($sectionStoragesViews as $sectionStoragesView) {
      // 1/2=> Charge les styles inclus directement dans les layouts.
      // Ces styles commencent par @use ...
      $this->generateSTyleFromEntity($sectionStoragesView, $DefaultStyle);
      // 2/2=> Charge les styles custom defini dans les entites ( via
      // l'interface graphque ).
      $this->generateCustomSTyleFromEntity($sectionStoragesView, $customStyle);
    }
  }
  
  protected function generateOverrideStyleFromEntity(LayoutBuilderEntityViewDisplay $entityView) {
    $layout_builder = $this->getSectionsForEntityView($entityView);
    if (!empty($layout_builder['allow_custom'])) {
      /**
       * Il faut charger tous les contenus, et creer les styles pour chaque
       * contenu surchargé.
       */
      $entities = $this->entityTypeManager()->getStorage($entityView->getTargetEntityTypeId())->loadByProperties([
        'type' => $entityView->getTargetBundle()
      ]);
      foreach ($entities as $entity) {
        $this->libraries = [];
        $customStyles = [];
        $this->getAllStylesFromOverrideEntity($entity, $customStyles);
        if ($this->libraries) {
          // if ($entity->id() == 52) {
          // dd('getAllStylesFromOverrideEntity', $customStyles,
          // $this->libraries);
          // }
          
          $entityTypeId = $entity->getEntityTypeId();
          $bundle = $entity->bundle() ? $entity->bundle() : $entityTypeId;
          $filename = $entityTypeId . '__' . $bundle . '__' . $entity->id();
          $this->librariesByEntity[$filename] = $this->libraries;
          $this->customsStyleByEntity[$filename] = $customStyles;
          $this->routes['entity.' . $entityTypeId . '.canonical.' . $bundle . '.' . $entity->id()] = $filename;
        }
      }
    }
  }
  
  /**
   * Permet de generer tous les styles en relation avec une page surcharger.
   */
  protected function getAllStylesFromOverrideEntity(ContentEntityBase $entity, array &$customStyles = []) {
    if ($entity->hasField('layout_builder__layout')) {
      $sections = [];
      $listSetions = $entity->get('layout_builder__layout')->getValue();
      $display_id = $entity->getEntityTypeId() . '__' . $entity->bundle() . '__' . $entity->id();
      if ($listSetions) {
        foreach ($listSetions as $value) {
          /**
           *
           * @var \Drupal\layout_builder\Section $section
           */
          $section = reset($value);
          $this->generateStyleForFieldsFromEntitySection($section, $display_id, $entity, false);
          $sections[] = $section;
        }
      }
      if ($sections) {
        $section_storage_override_id = $entity->getEntityTypeId() . '.' . $entity->bundle() . '.' . $entity->id();
        $this->generateStyleFromSection($sections, $section_storage_override_id, false);
        // Generer les styles definits au niveau de l'interface utilisateur.
        // (styles custom)
        if (empty($customStyles[$section_storage_override_id]))
          $customStyles[$section_storage_override_id] = [];
        $this->generateCustomStyles($sections, $customStyles[$section_storage_override_id]);
        // if ($entity->id() == 52) {
        // dump('generateCustomStyles', $section_storage_override_id,
        // $customStyles);
        // }
      }
      $this->getStyleFromReferences($entity, $customStyles);
    }
  }
  
  /**
   * Recupere les styles ajouter par les references.
   */
  protected function getStyleFromReferences(ContentEntityBase $entity, array &$customStyles = []) {
    $entityTypeId = $entity->getEntityTypeId();
    $bundle = $entity->bundle() ? $entity->bundle() : $entityTypeId;
    $referenceFields = $this->getReferenceFields($entityTypeId, $bundle);
    foreach ($referenceFields as $referenceField) {
      $values = $entity->get($referenceField['field_name'])->getValue();
      if ($values) {
        foreach ($values as $value) {
          $subEntity = $this->entityTypeManager()->getStorage($referenceField['field_settings']['target_type'])->load($value['target_id']);
          if ($subEntity) {
            // 1/2 => Si l'entites est surchargé.
            $this->getAllStylesFromOverrideEntity($subEntity, $customStyles);
            // 1/2 => Si l'enite n'est pas surchargé.
            $SubEntityTypeId = $subEntity->getEntityTypeId();
            $SubBundle = $subEntity->bundle() ? $subEntity->bundle() : $SubEntityTypeId;
            $DefaultStyle = [];
            // $customStyle = [];
            $this->generateStyleFromDefautlEntity($SubBundle, $SubEntityTypeId, $DefaultStyle, $customStyles);
            $this->libraries += $DefaultStyle;
            // if ($entity->id() == 52) {
            // dump('generateStyleFromDefautlEntity', $DefaultStyle,
            // $customStyle);
            // }
          }
        }
      }
    }
    // if ($entity->id() == 52) {
    // $test = [
    // 'entityTypeId' => $entityTypeId,
    // 'bundle' => $bundle,
    // 'id' => $entity->id()
    // ];
    // dump($test, $customStyles, $this->libraries);
    // }
  }
  
  /**
   * Pour les contenus surcharger.
   * Recuperer les librairies definies dans les sections.
   * Cela fonctionne dans la mesure ou une section contient un layout, et au
   * niveau de ce layout on a definit une library.
   *
   * @param array $sections
   * @param string $section_storage_id
   *        key of entity (doit contenir deux point par example
   *        cv_entity.cv_entity.150( cette nomenclature vise à eviter les
   *        doublons).
   */
  function generateStyleFromSection(array $sections, $section_storage_id, $buildThme = false) {
    if ($this->isAdmin && $this->shoMessage)
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via une entité surchargée ");
    // Genere les definits au niveau des layouts.
    $this->libraries[$section_storage_id] = $this->getLibraryForEachSections($sections);
  }
  
  /**
   * Recupere les styles custom definis au niveau des sections (interface
   * graphique).
   *
   * @param array $sections
   * @param array $styles
   */
  protected function generateCustomStyles(array $sections, array &$styles) {
    foreach ($sections as $section) {
      // initialisation.
      if (empty($styles['scss']))
        $styles['scss'] = [];
      if (empty($styles['js']))
        $styles['js'] = [];
      /**
       *
       * @var Section $section
       */
      $ar = $section->getLayoutSettings();
      //
      if (!empty($ar['id'])) {
        $FilesStyle = \Drupal\generate_style_theme\Entity\FilesStyle::loadByName($ar['id'], 'layout_custom_style');
        if ($FilesStyle) {
          $prefix = "\n";
          $prefix .= "// module : " . $FilesStyle->getModule() . ' || ' . $FilesStyle->label();
          $prefix .= " \n";
          $scss = $FilesStyle->getScss();
          if (!empty($scss))
            $styles['scss'][$ar['id']][] = $prefix . $scss;
          
          $js = $FilesStyle->getJs();
          if (!empty($js))
            $styles['js'][$ar['id']][] = $prefix . $js;
        }
      }
    }
  }
  
  /**
   * Recupere les styles definits au niveau des champs inclut dans les sections.
   *
   * @param Section $section
   * @param string $display_id
   * @param EntityInterface $entity
   * @param boolean $themeBuild
   */
  protected function generateStyleForFieldsFromEntitySection(Section $section, $display_id, EntityInterface $entity, $themeBuild = false) {
    $components = $section->getComponents();
    foreach ($components as $component) {
      $ar = $component->toArray();
      if (!empty($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'])) {
        $id = \str_replace(".", "__", $ar['configuration']['id']) . ':' . $entity->id();
        $this->addStyleFromFieldsEntitiesOverride($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'], $id, $display_id, 'fields', 'module', $themeBuild);
      }
    }
  }
  
  /**
   * Genere les styles par defaut pour le mode d'affichage.
   * Ces styles proviennent de "@stephane888/wbu-atomique/..."
   *
   * @param LayoutBuilderEntityViewDisplay $entity
   */
  protected function generateSTyleFromEntity(LayoutBuilderEntityViewDisplay $entityView, array &$styles) {
    $layout_builder = $this->getSectionsForEntityView($entityView);
    $sections = $layout_builder['sections'] ?? [];
    if ($sections) {
      $styles[$entityView->id()] = $this->getLibraryForEachSections($sections);
    }
  }
  
  /**
   * Genere les styles custom incluent dans les entites.
   *
   * @param LayoutBuilderEntityViewDisplay $entityView
   * @param array $styles
   */
  protected function generateCustomSTyleFromEntity(LayoutBuilderEntityViewDisplay $entityView, array &$styles) {
    $layout_builder = $this->getSectionsForEntityView($entityView);
    $sections = $layout_builder['sections'] ?? [];
    if ($sections) {
      if (empty($styles[$entityView->id()]))
        $styles[$entityView->id()] = [];
      $this->generateCustomStyles($sections, $styles[$entityView->id()]);
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
   * --
   */
  protected function saveRoutesInThemes($clean = true) {
    $defaultThemeName = $this->getDefaultTheme();
    $ModuleConf = $this->getConfigFOR_generate_style_theme();
    if ($defaultThemeName) {
      $conf = \Drupal\generate_style_theme\GenerateStyleTheme::getDynamicConfig($defaultThemeName, $ModuleConf);
      $config = $this->ConfigFactory->getEditable($conf['settings']);
      // Clean datas.
      if ($clean) {
        $config->set('routesname', []);
        $config->save();
      }
      foreach ($this->routes as $routeName => $value) {
        $config->set('routesname.' . $routeName, $defaultThemeName . '/' . $value);
      }
      $config->save();
    }
  }
  
  /**
   * Genrere directement les fichiers scss et js.
   */
  protected function generateFilesStyles() {
    $defaultThemeName = $this->getDefaultTheme();
    if (!empty($defaultThemeName)) {
      $ids = $this->entityTypeManager()->getStorage('config_theme_entity')->getQuery()->condition('hostname', $defaultThemeName)->accessCheck(false)->execute();
      if (!empty($ids)) {
        $auto_generate_entries = [];
        $entity = ConfigThemeEntity::load(reset($ids));
        $GenerateStyleTheme = new GenerateStyleTheme($entity);
        foreach ($this->librariesByEntity as $filename => $styles) {
          $librairiesStyles = $this->getArrayScssJs($styles);
          $customsStyles = $this->getArrayScssJs($this->customsStyleByEntity[$filename]);
          $GenerateStyleTheme->buildCustomScssFromArray($librairiesStyles['scss'], $filename, $customsStyles['scss']);
          $GenerateStyleTheme->buildCustomJsFromArray($librairiesStyles['js'], $filename, $customsStyles['js']);
          $auto_generate_entries[$filename] = './src/js/' . $filename . '.js';
        }
        $GenerateStyleTheme->autoGenerateEntries($auto_generate_entries);
      }
    }
    if ($this->shoMessage)
      $this->messenger()->addStatus("Vous devez regenerer votre theme");
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
      $this->entitiesGenerateDefautlStyles();
    }
  }
  
}