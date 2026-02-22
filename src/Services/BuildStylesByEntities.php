<?php

namespace Drupal\layoutgenentitystyles\Services;

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
   *
   * @var ConfigThemeEntity
   */
  protected $configThemeEntity;
  
  /**
   * Contient les bundle qui sont déjà passé par la génération
   *
   * @var array
   */
  protected $bundlesProcessed = [];
  /**
   * Permet de construire les pages et les sous entites.
   *
   * @var array
   */
  private $pages = [];
  private $CheckEntityTypeManagerExist = [];
  
  /**
   * Permet de parcourir les entites qui peuvent avoir les styles.
   */
  protected function entitiesGenerateDefautlStyles() {
    $configs = $this->getConfigs();
    if (!empty($configs['entities_pages'])) {
      foreach (array_keys($configs['entities_pages']) as $entity_type_id) {
        $typeEntities = $this->entityTypeManager()->getStorage($entity_type_id)->loadMultiple();
        $count = 0;
        foreach ($typeEntities as $bundle => $entityConfigType) {
          
          /**
           * Nous construisons les styles pour les modeles d'affichage.
           *
           * @var \Drupal\blockscontent\Entity\BlocksContentsType $entityType
           */
          if ($entityConfigType instanceof \Drupal\Core\Config\Entity\ConfigEntityBundleBase) {
            $entityType = $entityConfigType->getEntityType();
            $BundleOf = $entityType->getBundleOf();
            $DefaultStyle = [];
            $customStyle = [];
            /**
             * On le vide pour chaque modele d'affichage.
             *
             * @var \Drupal\layoutgenentitystyles\Services\BuildStylesByEntities $bundlesProcessed
             */
            $this->bundlesProcessed = [];
            /**
             * Contient les librairies issuent des champs, views ...
             *
             * @var \Drupal\layoutgenentitystyles\Services\BuildStylesByEntities $libraries
             */
            $this->libraries = [];
            
            if ($entity_type_id == "blocks_contents_type") {
              $count++;
            }
            /**
             * On charge uniquement les styles par defaut.
             */
            $this->generateStyleFromDefautlEntity($bundle, $BundleOf, $DefaultStyle, $customStyle, 'default');
            $this->prepareFiles($DefaultStyle, $customStyle, $BundleOf, $bundle);
            // if (($DefaultStyle || $customStyle) && $filename) {
            // if ($this->libraries) {
            // $DefaultStyle += $this->libraries;
            // }
            // $this->librariesByEntity[$filename] = $DefaultStyle;
            // $this->customsStyleByEntity[$filename] = $customStyle;
            // $this->routes['entity.' . $BundleOf . '.canonical.' . $bundle .
            // '.default'] = $filename;
            // }
            // Charge les styles surchargés.
            $sectionStoragesViews = $this->loadEntityViewDisplay($bundle, $BundleOf);
            foreach ($sectionStoragesViews as $sectionStoragesView) {
              $this->generateOverrideStyleFromEntity($sectionStoragesView);
            }
            // Permet d'avoir un apercu.
            $this->pages[$bundle . '.' . $BundleOf] = [
              'libraries' => $this->librariesByEntity,
              'custom_styles' => $this->customsStyleByEntity
            ];
          }
        }
      }
      
      $this->generateFilesStyles();
      $this->saveRoutesInThemes();
    }
    else {
      $this->messenger()->addWarning("Vous devez specifier les entities qui peuvent porter les styles");
    }
  }
  
  /**
   * Prepare la generation des fichiers pour les modes d'affichage par defaut.
   *
   * @param array $DefaultStyle
   * @param array $customStyle
   * @param string $BundleOf
   * @param string $bundle
   */
  private function prepareFiles(array $DefaultStyle, array $customStyle, string $BundleOf, string $bundle) {
    if (($DefaultStyle || $customStyle)) {
      $filename = $BundleOf . '__' . $bundle;
      if ($this->libraries) {
        $DefaultStyle += $this->libraries;
      }
      $this->librariesByEntity[$filename] = $DefaultStyle;
      $this->customsStyleByEntity[$filename] = $customStyle;
      $this->routes['entity.' . $BundleOf . '.canonical.' . $bundle . '.default'] = $filename;
    }
  }
  
  /**
   *
   * @param string $bundle
   * @param string $entityTypeId
   * @param array $DefaultStyle
   * @param array $customStyle
   * @param string $display_mode
   */
  protected function generateStyleFromDefautlEntity(string $bundle, string $entityTypeId, &$DefaultStyle = [], &$customStyle = [], $display_mode = null) {
    if (empty($this->CheckEntityTypeManagerExist[$entityTypeId])) {
      $this->CheckEntityTypeManagerExist[$entityTypeId] = $this->entityTypeManager()->getStorage($entityTypeId) ? 'found' : 'not_found';
      if ($this->CheckEntityTypeManagerExist[$entityTypeId] == 'not_found') {
        $this->messenger()->addWarning("L'entité '$entityTypeId' n'existe pas");
      }
    }
    $sectionStoragesViews = $this->loadEntityViewDisplay($bundle, $entityTypeId);
    
    if ($display_mode) {
      if (isset($sectionStoragesViews["$entityTypeId.$bundle.$display_mode"]))
        $sectionStoragesViews = [
          $display_mode => $sectionStoragesViews["$entityTypeId.$bundle.$display_mode"]
        ];
      else
        $sectionStoragesViews = [];
    }
    foreach ($sectionStoragesViews as $key => $sectionStoragesView) {
      /**
       * Les affiches par defaut sont unique.
       */
      if ($this->canProcessBundle($key)) {
        // 1/2=> Charge les styles inclus directement dans les layouts.
        // Ces styles commencent par @use ...
        $this->generateSyleFromEntityView($sectionStoragesView, $DefaultStyle, $customStyle);
        // 2/2=> Charge les styles custom defini dans les entites ( via
        // l'interface graphique ).
        $this->generateCustomSTyleFromEntity($sectionStoragesView, $customStyle);
      }
    }
  }
  
  protected function generateOverrideStyleFromEntity(LayoutBuilderEntityViewDisplay $entityView) {
    /**
     * Il faut charger tous les contenus, et creer les styles pour chaque
     * contenu surchargé.
     */
    $entities = $this->entityTypeManager()->getStorage($entityView->getTargetEntityTypeId())->loadByProperties([
      'type' => $entityView->getTargetBundle()
    ]);
    /**
     *
     * @todo à transformer en processus batch ou à partir de la fonction
     *       principale.
     */
    foreach ($entities as $entity) {
      $this->libraries = [];
      $customStyles = [];
      /**
       * On le vide pour chaque entités.
       *
       * @var \Drupal\layoutgenentitystyles\Services\BuildStylesByEntities $bundlesProcessed
       */
      $this->bundlesProcessed = [];
      $this->generateOverrideStyleFromOneEntity($entity, $customStyles, False);
    }
  }
  
  /**
   * Recupere tous les styles definie sur une entité surchargé et les styles
   * ajoutés par les references (generalement entité paragraph).
   * NB: les entites definie au niveau l'affichage du type d'entité sont generés
   * ici generateStyleFromDefautlEntity.
   *
   * @param ContentEntityBase $entity
   * @param array $customStyles
   * @param boolean $override
   */
  protected function generateOverrideStyleFromOneEntity(ContentEntityBase $entity, &$customStyles = [], $override = True) {
    if ($override) {
      $this->libraries = [];
    }
    $this->getAllStylesFromOverrideEntity($entity, $customStyles);
    if ($this->libraries) {
      $entityTypeId = $entity->getEntityTypeId();
      $bundle = $entity->bundle() ? $entity->bundle() : $entityTypeId;
      $filename = $entityTypeId . '__' . $bundle . '__' . $entity->id();
      $this->librariesByEntity[$filename] = $this->libraries;
      $this->customsStyleByEntity[$filename] = $customStyles;
      $this->routes['entity.' . $entityTypeId . '.canonical.' . $bundle . '.' . $entity->id()] = $filename;
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
          $section = reset($value);
          $sections[] = $section;
        }
        $this->generateStyleForFieldsFromEntitySections($sections, $display_id, $entity, false, $customStyles);
        $section_storage_override_id = $entity->getEntityTypeId() . '.' . $entity->bundle() . '.' . $entity->id();
        $this->generateStyleFromSection($sections, $section_storage_override_id, false);
        // Generer les styles definits au niveau de l'interface utilisateur.
        // (styles custom)
        if (empty($customStyles[$section_storage_override_id]))
          $customStyles[$section_storage_override_id] = [];
        $this->generateCustomStyles($sections, $customStyles[$section_storage_override_id]);
      }
    }
    $this->getStyleFromReferences($entity, $customStyles);
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
            // 1/2 => Si l'entite n'est pas surchargé.
            $SubEntityTypeId = $subEntity->getEntityTypeId();
            $SubBundle = $subEntity->bundle() ? $subEntity->bundle() : $SubEntityTypeId;
            $DefaultStyle = [];
            /**
             *
             * @todo il faudra charger specifiquement les styles necessaire, en
             *       fonction du mode d'affichage.
             */
            $this->generateStyleFromDefautlEntity($SubBundle, $SubEntityTypeId, $DefaultStyle, $customStyles);
            $this->libraries += $DefaultStyle;
          }
        }
      }
    }
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
  protected function generateStyleFromSection(array $sections, $section_storage_id, $buildThme = false) {
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
          $prefix .= "// ====================================================================== \n";
          $prefix .= "// module : " . $FilesStyle->getModule() . ' || ' . $FilesStyle->label();
          $prefix .= "\n";
          $prefix .= "// ======================================================================";
          $prefix .= "\n";
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
  protected function generateStyleForFieldsFromEntitySections(array $sections, $display_id, EntityInterface $entity, $themeBuild = false, array &$customStyles = []) {
    /**
     *
     * @var LayoutBuilderEntityViewDisplay $entity
     */
    foreach ($sections as $section) {
      $components = $section->getComponents();
      foreach ($components as $component) {
        $ar = $component->toArray();
        if (!empty($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'])) {
          $id = \str_replace(".", "__", $ar['configuration']['id']) . ':' . $entity->id();
          $this->addStyleFromFieldsEntitiesOverride($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'], $id, $display_id, 'fields', 'module', $themeBuild);
        }
        elseif (!empty($ar['configuration']['provider']) && $ar['configuration']['provider'] == 'views' && !empty($ar['configuration']['id'])) {
          $parts = explode(':', $ar['configuration']['id'])[1];
          [
            $view_id,
            $view_display_id
          ] = explode('-', $parts, 2);
          if ($view_id && $view_display_id) {
            /**
             *
             * @var \Drupal\views\ViewExecutable $view
             */
            $view = \Drupal\views\Views::getView($view_id);
            if ($view) {
              $view->setDisplay($view_display_id);
              // Ajout le style d'affichages ( par exemple swipper ).
              $styles = $view->getDisplay()->getOption('style');
              if (!empty($styles['options']['layoutgenentitystyles_view'])) {
                $this->addStyleFromView($styles['options']['layoutgenentitystyles_view'], $view_id, $view_display_id);
              }
              
              $filters = $view->getDisplay()->getOption('filters');
              $bundles = null;
              $entityConfigTypeId = $view->getBaseEntityType()->getBundleEntityType();
              $entityTypeId = $view->getBaseEntityType()->id();
              if (isset($filters["type"]["value"])) {
                $bundles = $view->getDisplay()->getOption('filters')["type"]["value"];
              }
              else {
                $bundles = $this->entityTypeManager()->getStorage($entityConfigTypeId)->loadMultiple();
              }
              $bundles = array_keys($bundles);
              foreach ($bundles as $bundle) {
                $view_mode = $view->getDisplay()->getOption("row")["options"]["view_mode"] ?? null;
                $defaultStyles = [];
                $this->generateStyleFromDefautlEntity($bundle, $entityTypeId, $defaultStyles, $customStyles, $view_mode);
                $this->libraries += $defaultStyles;
              }
            }
          }
        }
      }
    }
  }
  
  /**
   * La variable bundlesProcessed doit etre vider pour chaque page ou modele
   * d'affichage.
   *
   * @return bool
   */
  protected function canProcessBundle($bundleKey): bool {
    if (!empty($this->bundlesProcessed[$bundleKey])) {
      return false;
    }
    $this->bundlesProcessed[$bundleKey] = $bundleKey;
    return true;
  }
  
  function getPages(): array {
    return $this->pages;
  }
  
  /**
   * Genere les styles par defaut pour le mode d'affichage.
   * Ces styles proviennent de "@stephane888/wbu-atomique/..."
   *
   * @param LayoutBuilderEntityViewDisplay $entity
   */
  protected function generateSyleFromEntityView(LayoutBuilderEntityViewDisplay $entityView, array &$DefaultStyle, &$customsStyles = []) {
    $layout_builder = $this->getSectionsForEntityView($entityView);
    $sections = $layout_builder['sections'] ?? [];
    
    if ($sections) {
      $DefaultStyle[$entityView->id()] = $this->getLibraryForEachSections($sections);
      $display_id = \str_replace('.', '_', $entityView->id());
      $this->generateStyleForFieldsFromEntitySections($sections, $display_id, $entityView, false, $customsStyles);
    }
  }
  
  /**
   * Genere les styles custom incluent dans les entites.
   *
   * @param LayoutBuilderEntityViewDisplay $entityView
   * @param array $styles
   */
  protected function generateCustomSTyleFromEntity(LayoutBuilderEntityViewDisplay $entityView, array &$customsStyles) {
    $layout_builder = $this->getSectionsForEntityView($entityView);
    $sections = $layout_builder['sections'] ?? [];
    if ($sections) {
      if (empty($customsStyles[$entityView->id()]))
        $customsStyles[$entityView->id()] = [];
      $this->generateCustomStyles($sections, $customsStyles[$entityView->id()]);
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
      /**
       *
       * @var \Drupal\Core\Config\Config $config
       */
      $config = $this->ConfigFactory->getEditable($conf['settings']);
      // Clean datas.
      if ($clean) {
        $config->set('routesname', []);
        $config->save();
        foreach ($this->routes as $routeName => $value) {
          $config->set('routesname.' . $routeName, $defaultThemeName . '/' . $value);
        }
        $config->save();
      }
      else {
        // On evite la sauvegarde.
        $saveIt = false;
        foreach ($this->routes as $routeName => $value) {
          $oldValue = $config->get('routesname.' . $routeName);
          if ($oldValue) {
            $saveIt = true;
            $config->set('routesname.' . $routeName, $defaultThemeName . '/' . $value);
          }
        }
        if ($saveIt) {
          $config->save();
        }
      }
    }
  }
  
  public function getActiveConfigThemeEntity() {
    if (!$this->configThemeEntity) {
      
      $defaultThemeName = $this->getDefaultTheme();
      if (!empty($defaultThemeName)) {
        
        $ids = $this->entityTypeManager()->getStorage('config_theme_entity')->getQuery()->condition('hostname', $defaultThemeName)->accessCheck(false)->execute();
        if (!empty($ids)) {
          $this->configThemeEntity = ConfigThemeEntity::load(reset($ids));
        }
      }
    }
    return $this->configThemeEntity;
  }
  
  /**
   * Genrere directement les fichiers scss et js.
   */
  protected function generateFilesStyles($generateAll = True) {
    if ($generateAll) {
      $auto_generate_entries = [
        'global-style' => './src/js/global-style.js',
        'vendor-style' => './src/js/vendor-style.js',
        'mail-style' => './src/js/mail-style.js'
      ];
    }
    else
      $auto_generate_entries = [];
    $entity = $this->getActiveConfigThemeEntity();
    if ($entity) {
      $GenerateStyleTheme = new GenerateStyleTheme($entity);
      foreach ($this->librariesByEntity as $filename => $styles) {
        $librairiesStyles = $this->getArrayScssJs($styles);
        // On charge les styles statiques.
        $routePath = $this->pathFromFileName($filename);
        if (!empty($routePath)) {
          $styles = $this->ManageFileCustomStyle->generateSpecificStyles($routePath);
          $this->customsStyleByEntity[$filename]['generate_style_theme.styles.custom'] = [
            'scss' => [
              'generate_style_theme__styles__custom' => [
                $styles['scss']
              ]
            ],
            'js' => [
              'generate_style_theme__styles__custom' => [
                $styles['js']
              ]
            ]
          ];
        }
        $customsStyles = $this->getArrayScssJs($this->customsStyleByEntity[$filename]);
        $GenerateStyleTheme->buildCustomScssFromArray($librairiesStyles['scss'], $filename, $customsStyles['scss']);
        $GenerateStyleTheme->buildCustomJsFromArray($librairiesStyles['js'], $filename, $customsStyles['js']);
        $auto_generate_entries[$filename] = './src/js/' . $filename . '.js';
        if ($generateAll && $this->shoMessage)
          $this->messenger()->addStatus("Les fichiers scss et js(node) ont été genéré, vous devez effacé les caches et lancer manuellement la creation des fichiers css et js utilisé par le theme.");
      }
      $GenerateStyleTheme->autoGenerateEntries($auto_generate_entries, $generateAll);
    }
  }
  
  /**
   * Permet de generer tous les styles et de les ajouter dans la configuration
   * du theme actif.
   */
  public function generateAllFilesStyles() {
    $ModuleConf = $this->getConfigFOR_generate_style_theme();
    // On construit les styles en fonction des entités.
    if (!empty($ModuleConf['tab1']) && $ModuleConf['tab1']['save_multifile'] == 1) {
      // 1 - Genere les fichiers de base.
      $customStyles = [];
      $this->loadStyleFromBlocs($customStyles);
      
      $this->addStylesToConfigTheme(true, $customStyles);
      // On regenere le fichier custom d'email.
      $this->ManageFileMailStyle->generateCustomFile();
      // 2 - Genere les fichiers dynamique.
      $this->entitiesGenerateDefautlStyles();
    }
    else {
      \Drupal::messenger()->addWarning("Vous devez activer la generation multiple de fichiers de styles");
    }
  }
  
  /**
   * Permet de generer tous les styles et de les ajouter dans la configuration
   * du theme actif.
   */
  public function generateFileForEntity(ContentEntityBase $entity): array {
    $ModuleConf = $this->getConfigFOR_generate_style_theme();
    // On construit les styles en fonction des entités.
    if (!empty($ModuleConf['tab1']) && $ModuleConf['tab1']['save_multifile'] == 1) {
      $this->libraries = [];
      // 1- On recupere les styles definie au niveau de l'affichage par defaut.
      $DefaultStyle = [];
      $customStyle = [];
      $BundleOf = $entity->getEntityTypeId();
      $bundle = $entity->bundle();
      $this->generateStyleFromDefautlEntity($bundle, $BundleOf, $DefaultStyle, $customStyle, 'default');
      $this->prepareFiles($DefaultStyle, $customStyle, $BundleOf, $bundle);
      // 2 - Genere les fichiers dynamique.
      $this->libraries = [];
      $this->generateOverrideStyleFromOneEntity($entity);
      $this->generateFilesStyles(False);
      $this->saveRoutesInThemes(False);
      if ($ModuleConf['tab1']['run_node']) {
        $configThemeEntity = $this->getActiveConfigThemeEntity();
        $GenerateStyleTheme = new GenerateStyleTheme($configThemeEntity);
        $customFiles = \array_keys($this->librariesByEntity);
        $GenerateStyleTheme->RunNpmCustom($customFiles);
        if ($this->shoMessage)
          $this->messenger()->addStatus("Vous devez recharger votre page avec Ctrl+F5");
      }
    }
    return $this->librariesByEntity;
  }
  
  /**
   * Certains entites sont ajouté au niveau des blocs c'est generalement le cas
   * du menus, footers et autres ...
   */
  protected function loadStyleFromBlocs(array &$customStyles = []) {
    $blocks = $this->getCurrentblock();
    foreach ($blocks as $block) {
      /**
       *
       * @var \Drupal\block\Entity\Block $block
       */
      $settings = $block->get('settings');
      // Generalement pour les block_content.
      if (!empty($settings['id'])) {
        $entity_type_id = null;
        $entity = null;
        if (str_contains($settings['id'], ":")) {
          [
            $entity_type_id,
            $uuid_entity
          ] = explode(":", $settings['id']);
          if ($this->entityTypeManager()->hasDefinition($entity_type_id))
            $entity = \Drupal::service('entity.repository')->loadEntityByUuid($entity_type_id, $uuid_entity);
        }
        // Si on utilise le module entity_block.
        elseif (!empty($settings['entity'])) {
          $entity_type_id = explode(':', $block->get('plugin'))[1] ?? null;
          if ($entity_type_id && $this->entityTypeManager()->hasDefinition($entity_type_id)) {
            $entity = $this->entityTypeManager()->getStorage($entity_type_id)->load($settings['entity']);
          }
        }
        
        if ($entity_type_id && $entity) {
          // 1/3 => Si l'entité est surchargé.
          $this->getAllStylesFromOverrideEntity($entity, $customStyles);
          // 2/3 => Si l'entité n'est pas surchargé.
          $EntityTypeId = $entity->getEntityTypeId();
          $Bundle = $entity->bundle() ? $entity->bundle() : $EntityTypeId;
          $DefaultStyle = [];
          $this->generateStyleFromDefautlEntity($Bundle, $EntityTypeId, $DefaultStyle, $customStyles);
          $this->libraries += $DefaultStyle;
          // 3/3 les styles incluent dans les references.
          $this->getStyleFromReferences($entity, $customStyles);
          
          $this->pages[$Bundle . '.' . $EntityTypeId] = [
            'libraries' => $this->librariesByEntity,
            'custom_styles' => $this->customsStyleByEntity
          ];
        }
      }
    }
  }
  
  private function pathFromFileName(string $filename) {
    $path_array = explode('__', $filename);
    $bundle = "";
    switch (count($path_array)) {
      case 1:
        // nothing to do
        break;
      case 2:
        $bundle = $path_array[0];
        break;
      case 3:
        $bundle = $path_array[0];
        $entity_id = $path_array[2];
        break;
    }
    $path = "";
    if ($bundle === "blocks_contents" || $bundle == "site_internet_entity") {
      $bundle = str_replace("_", "-", $bundle);
    }
    if (!empty($entity_id)) {
      $path = "/$bundle/$entity_id";
    }
    return $path;
  }
  
}
