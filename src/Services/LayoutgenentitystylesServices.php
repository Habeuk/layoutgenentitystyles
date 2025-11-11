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

/**
 *
 * @author stephane
 * @deprecated car approche pas du tout efficace au niveau des perfoemances (
 *             google page speed ).
 */
class LayoutgenentitystylesServices extends BuilderStylesBase {
  /**
   * Contient la liste des plugins d'affichage.
   *
   * @var array
   */
  protected $sectionStorages = null;
  
  /**
   * Contient les definitions d'entites qui permettront de generer les styles.
   *
   * @var array
   */
  protected $sectionStoragesByLayout = [];
  
  /**
   * On recupere la liste des plugins d'affichage d'entite validé en funcion de
   * la configurations.
   *
   * @return array
   */
  public function getListSectionStorages() {
    if (!$this->sectionStorages) {
      $this->sectionStorages = [];
      /**
       * L'entite qui gere les affichages.
       *
       * @var string $entity_type_id
       */
      $entity_type_id = 'entity_view_display';
      $DefaultsSectionStorages = $this->entityTypeManager()->getStorage($entity_type_id)->loadByProperties();
      // On filtre les affichages par ceux donc l'utilisateur à valider.
      $config = $this->getConfigs();
      $entity_auto_generate = array_filter($config['entity_auto_generate'], function ($value) {
        return $value ?? false;
      });
      if ($entity_auto_generate) {
        $entity_auto_generate = array_keys($entity_auto_generate);
        $this->sectionStorages = array_filter($DefaultsSectionStorages, function ($key) use ($entity_auto_generate) {
          foreach ($entity_auto_generate as $valid_entity_type_id) {
            if (str_contains($key, $valid_entity_type_id . '.'))
              return true;
          }
          return false;
        }, ARRAY_FILTER_USE_KEY);
        // On recupere les paragraphes attaché à un layout.
        // ( Dans cette approche, on considere que tous les layouts sont
        // associés à des paragraphes ).
        /**
         *
         * @var \Drupal\layoutgenentitystyles\Services\ParagraphLoader $paragraph_loader
         */
        $paragraph_loader = \Drupal::service('layoutgenentitystyles.paragraph_loader');
        $grouped = $paragraph_loader->loadGroupedByParagraphType($entity_auto_generate);
        $sectionStorages = [];
        foreach ($grouped as $entity_type_id => $entity_type_ids) {
          foreach ($entity_type_ids as $infor_entity) {
            // On recupere les configurations d'affichage liée au paragraphs.
            $seach_key = 'paragraph.' . $infor_entity['paragraph_type'] . '.';
            $sectionStorages += array_filter($DefaultsSectionStorages, function ($key) use ($seach_key) {
              return str_contains($key, $seach_key) ? true : false;
            }, ARRAY_FILTER_USE_KEY);
          }
        }
        $this->sectionStorages += $sectionStorages;
      }
    }
    return $this->sectionStorages;
  }
  
  /**
   * Pemet de charger les librairies ajoutées au niveaux des vues.
   */
  protected function loadAllViews() {
    $viewEntity = \Drupal::entityTypeManager()->getStorage('view');
    
    $ids = $viewEntity->getQuery()->condition('status', true)->execute();
    if (!empty($ids)) {
      $views = $viewEntity->loadMultiple($ids);
      // dump($views);
      foreach ($views as $view) {
        /**
         *
         * @var \Drupal\views\Entity\View $view
         */
        $build = $view->toArray();
        
        if (!empty($build['display'])) {
          foreach ($build['display'] as $display_id => $value) {
            if (!empty($value['display_options']['style']['options']['layoutgenentitystyles_view'])) {
              $subdir = '';
              $type = 'module';
              $themeBuild = false;
              $this->addStyleFromView($value['display_options']['style']['options']['layoutgenentitystyles_view'], $build['id'], $display_id, $subdir, $type, $themeBuild);
            }
          }
        }
      }
    }
  }
  
  /**
   * Permet de generer tous les styles et de les ajouter dans la configuration
   * du theme actif.
   */
  function generateAllFilesStyles() {
    $ModuleConf = $this->getConfigFOR_generate_style_theme();
    // On construit les styles de maniere generale.
    if (!empty($ModuleConf['tab1']) && $ModuleConf['tab1']['save_multifile'] !== 1) {
      $this->loadAllViews();
      $this->sectionStoragesByLayout = $this->getListSectionStorages();
      foreach ($this->sectionStoragesByLayout as $entityView) {
        $this->generateSTyleFromEntity($entityView, false);
        $this->getUxStyleFromEntity($entityView);
        $this->generateStyleFromFieldConfigDisplay($entityView, false);
        $layout_builder = $this->getSectionsForEntityView($entityView);
        // Si l'entité d'affichage accepte la surcharge et que nous sommes sur
        // le
        // rendu par defaut.
        if (!empty($layout_builder['allow_custom']) && $entityView->getMode() == 'default') {
          $bundle = $entityView->getTargetBundle();
          $EntityTypeId = $entityView->getTargetEntityTypeId();
          /**
           *
           * @var \Drupal\Core\Entity\EntityStorageInterface $entitiesContentsStorage
           */
          $entitiesContentsStorage = $this->entityTypeManager()->getStorage($entityView->getTargetEntityTypeId());
          $key = $entitiesContentsStorage->getEntityType()->getKey('bundle');
          $BundleEntityType = $entitiesContentsStorage->getEntityType()->getBundleEntityType();
          if ($BundleEntityType && $key) {
            $entities = $this->entityTypeManager()->getStorage($EntityTypeId)->loadByProperties([
              $key => $bundle,
              'status' => 1
            ]);
          }
          else
            $entities = $this->entityTypeManager()->getStorage($EntityTypeId)->loadByProperties();
          /**
           * Concernant $entities il contient les contenus publiés et ceux non
           * publiées.
           * Pour la suite il faudra regrouper les styles par types d'entites et
           * par entites de base surcharger.
           * Exemple: si on a un type node.page.
           * - On aurra un fichier generer à partir de node.page
           * - Si le contenu qu'on visite est surchargé
           * (layout_builder__layout),
           * on va egalement charger les styles surcharge ( pour ce contenu ).
           */
          if ($entities) {
            foreach ($entities as $entity) {
              $this->getAllStylesFromOverrideEntity($entity);
            }
          }
        }
      }
      
      $this->getCustomLibrary();
      $this->loadStyleFromBlocs();
      // La il ya un soucis, il faut determiner si elle detruit les styles,
      // ajoutées par la configuration surcharger.
      /**
       * Effectivement, elles sont detruite les styles envoyés par l'autre
       * mecanisme.
       */
      // On masque pour le moment.
      // il faudra soit separer les sauvegarde au niveau du theme, et ajouté un
      // moyen qui permet de mettre à jours les configirations surcharger.
      $this->addStylesToConfigTheme(true);
      
      // On regenere le fichier custom.
      $this->ManageFileCustomStyle->generateCustomFile(true);
      // On regenere le fichier custom d'email.
      $this->ManageFileMailStyle->generateCustomFile();
    }
  }
  
  /**
   * Permet de recuperer tous les styles ajouter via l'interface des Scss.js de
   * layout.
   */
  public function getComponentsOverrides() {
    // On filtre les affichages par ceux donc l'utilisateur à valider.
    $config = $this->getConfigs();
    $entity_auto_generate = array_filter($config['entity_auto_generate'], function ($value) {
      return $value ?? false;
    });
    $entity_auto_generate = array_keys($entity_auto_generate);
    foreach ($entity_auto_generate as $entity_type_id) {
      /**
       *
       * @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $storage
       */
      $storage = $this->entityTypeManager()->getStorage($entity_type_id);
      if (!$storage && !($storage instanceof \Drupal\Core\Entity\Sql\SqlContentEntityStorage))
        continue;
      $layoutEntitiesViews = [];
      // Verifions si l'entite a des bundles.
      if ($storage->getEntityType()->getBundleEntityType()) {
        $BundleEntityType = $storage->getEntityType()->getBundleEntityType();
        $BundleEntities = $this->entityTypeManager()->getStorage($BundleEntityType)->loadMultiple();
        // Les bundles qui ont un affichage utilisant les layouts.
        foreach ($BundleEntities as $BundleEntity) {
          $this->getEntitiesModeDisplay($layoutEntitiesViews, $entity_type_id, $BundleEntity->id());
        }
      }
      else {
        $this->getEntitiesModeDisplay($layoutEntitiesViews, $entity_type_id, $entity_type_id);
      }
      if ($layoutEntitiesViews) {
        foreach ($layoutEntitiesViews as $bundle_id => $layoutEntities) {
          foreach ($layoutEntities as $layout_builder) {
            $key = $storage->getEntityType()->getKey('bundle');
            $query = $storage->getQuery();
            if ($key)
              $query->condition($key, $bundle_id);
            $ids = $query->accessCheck(TRUE)->execute();
            if ($ids)
              if ($layout_builder['allow_custom']) {
                $entities = $this->entityTypeManager()->getStorage($entity_type_id)->loadMultiple($ids);
                if ($entities) {
                  // on ajoute les styles par defaut.
                  $this->getOverrideScss($layout_builder['sections']);
                  // On ajoute les styles par defaut.
                  foreach ($entities as $entity) {
                    $sections = [];
                    $listSetions = $entity->get('layout_builder__layout')->getValue();
                    // $section_storage = $entity->getEntityTypeId() . '.' .
                    // $entity->bundle() . '.' . $entity->id();
                    foreach ($listSetions as $value) {
                      $sections[] = reset($value);
                    }
                    $this->getOverrideScss($sections);
                  }
                }
              }
              else {
                // on ajoute les styles par defaut.
                $this->getOverrideScss($layout_builder['sections']);
              }
          }
        }
      }
    }
  }
  
  /**
   * Lors de la generation d'un site, les styles ajoute au paragraph ne sont pas
   * creer afin que ce processus soit rapide.
   * Cette fonction permet d'ajouter ces styles dans la table "files_style".
   */
  protected function getOverrideScss(array $sections) {
    foreach ($sections as $section) {
      /**
       *
       * @var \Drupal\layout_builder\Section $section
       */
      $storage = $section->getLayoutSettings();
      $this->loadPluginScss()->addConfigs($storage);
    }
  }
  
  /**
   * Specifique à wb-horizon.
   *
   * @return \Drupal\layout_custom_style\StyleScssPluginManager
   */
  protected function loadPluginScss() {
    if (!$this->StyleScssPlugin) {
      $this->StyleScssPlugin = \Drupal::service('plugin.manager.style_scss');
    }
    return $this->StyleScssPlugin;
  }
  
  /**
   * Recupere les modes d'affichages.
   *
   * @param array $overridesEntitiesViews
   * @param string $entity_type_id
   * @param string $bundle_id
   */
  protected function getEntitiesModeDisplay(array &$overridesEntitiesViews, $entity_type_id, $bundle_id) {
    /**
     * Les modes d'affichages.
     *
     * @var array $entitiesViews
     */
    $entitiesViews = $this->entityTypeManager()->getStorage('entity_view_display')->loadByProperties([
      'targetEntityType' => $entity_type_id,
      'bundle' => $bundle_id
    ]);
    foreach ($entitiesViews as $entityView) {
      /**
       *
       * @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay
       */
      if ($entityView instanceof \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay) {
        $layout_builder = $this->getSectionsForEntityView($entityView);
        if (!empty($layout_builder['enabled']) && $layout_builder['sections']) {
          $overridesEntitiesViews[$bundle_id][$entityView->getMode()] = $layout_builder;
        }
      }
    }
  }
  
  /**
   * Recupere les styles definie au niveau de l'entite (override) et au niveau
   * des champs.
   *
   * @param EntityInterface $entity
   */
  protected function getAllStylesFromOverrideEntity(EntityInterface $entity) {
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
      }
    }
  }
  
  /**
   * Ajout le style apres l'enregistrement d'une view style d'affichage
   * disposant d'une library.
   *
   * @param string $library
   */
  function addStyleFromView(string $library, $id, $display_id, $subdir = '', $type = 'module', $themeBuild = true) {
    [
      $module,
      $filename
    ] = explode("/", $library);
    if ($module && $filename) {
      $key = $module . '.views__' . $id . '.' . $display_id;
      $this->libraries[$key] = [
        'scss' => [],
        'js' => []
      ];
      $this->LoadStyleFromMod->getStyleDefault($module, $filename, $this->libraries[$key], $subdir, $type);
      if ($themeBuild)
        $this->addStylesToConfigTheme();
    }
  }
  
  /**
   *
   * @param string $library
   * @param string $id
   * @param string $display_id
   * @param string $subdir
   */
  function addStyleFromModule(string $library, $id, $display_id, $subdir = '', $saveStyle = true) {
    $this->addStyleFromExtention($library, $id, $display_id, $subdir, 'module', $saveStyle);
  }
  
  /**
   *
   * @param string $library
   * @param string $id
   * @param string $display_id
   * @param string $subdir
   */
  function addStyleFromTheme(string $library, $id, $display_id, $subdir = 'dynamic_styles', $saveStyle = true) {
    $this->addStyleFromExtention($library, $id, $display_id, $subdir, 'theme', $saveStyle);
  }
  
  /**
   * Ajout le style apres l'enregistrement d'une entité (type d'affichage)
   * disposant d'une library, ou tout autre module.
   * SI on regenere les styles on a perd ces styles. ( correction baique: On va
   * les ajoutés dans une variable de configuration pour le momment, apres on
   * verra comment les gerer de maniere dynamique.)
   * on le fait dans la config du module.
   *
   * @param string $library
   */
  protected function addStyleFromExtention(string $library, $id, $display_id, $subdir = '', $type = 'module', $saveStyle = true) {
    [
      $module,
      $filename
    ] = explode("/", $library);
    if ($module && $filename) {
      $this->libraries[$module . '.' . $id . '.' . $display_id] = [
        'scss' => [],
        'js' => []
      ];
      $this->LoadStyleFromMod->getStyleDefault($module, $filename, $this->libraries[$module . '.' . $id . '.' . $display_id], $subdir, $type);
      $this->addStylesToConfigTheme();
      if ($saveStyle)
        $this->saveCustomLibrary($library, $id, $display_id, $module, $filename, $subdir, $type);
    }
  }
  
  /**
   * Permet d'ajouter les styles provenant d'un plugin block.
   *
   * @param string $library
   * @param \Drupal\Core\Block\BlockPluginInterface $block
   * @param string $display_id
   */
  function addStyleFromPluginBlock(\Drupal\Core\Block\BlockPluginInterface $block, $display_id = null) {
    if (!$display_id)
      $display_id = 'default';
    $confs = $block->getConfiguration();
    if (!empty($confs['layoutgenentitystyles_view'])) {
      $this->addStyleFromModule($confs['layoutgenentitystyles_view'], $block->getPluginId(), $display_id, 'block');
    }
    else {
      $this->messenger()->addWarning("Le champs layoutgenentitystyles_view est vide ou n'existe pas");
    }
  }
  
  /**
   * Sauvegarde un style custom de maniere permanente.
   * Le but de cette fonction est d'eviter de perdre les librairies lors de la
   * regeneration des styles.
   * Mais cette approche devrai etre ameliorer ou trouver une autre logique.
   * /// logique en place.
   * 1- On sauvegarde uniquement les styles ajouter via les modules et les
   * styles liées aux entites ne seront plus sauvegarder. On va parcourrir les
   * entites et recuperer les differents styles ajouté par ces dernieres.
   */
  function saveCustomLibrary($library, $id, $display_id, $module, $filename, $subdir, $type = 'module') {
    $config = $this->ConfigFactory->getEditable('layoutgenentitystyles.settings');
    $list = $config->get('list_style');
    if (!$list) {
      $list = [];
    }
    $list[$module . '---' . $filename] = [
      'id' => $id,
      'display_id' => $display_id,
      'library' => $library,
      'subdir' => $subdir,
      'type' => $type
    ];
    $config->set('list_style', $list);
    $config->save();
  }
  
  /**
   * Genere les styles pour le mode d'affichage non surcharger.
   *
   * @param LayoutBuilderEntityViewDisplay $entity
   */
  function generateSTyleFromEntity(LayoutBuilderEntityViewDisplay $entityView, $themeBuild = true) {
    $layout_builder = $this->getSectionsForEntityView($entityView);
    $sections = $layout_builder['sections'] ?? [];
    if ($sections) {
      $this->libraries[$entityView->id()] = $this->getLibraryForEachSections($sections);
      if ($themeBuild)
        $this->addStylesToConfigTheme();
    }
  }
  
  /**
   * Permet de recuperer les styles ajoutés par l'utilisateur et de les ajoutes
   * en BD.
   *
   * @param LayoutBuilderEntityViewDisplay $entityView
   */
  public function getUxStyleFromEntity(LayoutBuilderEntityViewDisplay $entityView) {
    $layout_builder = $this->getSectionsForEntityView($entityView);
    $sections = $layout_builder['sections'] ?? [];
    if ($sections) {
      $this->getOverrideScss($sections);
    }
  }
  
  /**
   * Permet de generer les styles à partir de la configuration des champs.
   * Explication :
   * on souhaite facilement afficher les champs tels que les bouttons de RX, des
   * champs complexes du profil CV et autres;
   * Pour facilier cette approche on ira du coté des champs, definir des champs
   * complexe permettant de sauvegarde plusieurs données.
   * Nous souhaitons egalement garder la logique de generation des styles.
   * On definit une logique :
   * Dans la configuration du formatter de champs, on doit ajouter une entrée
   * "layoutgenentitystyles_view". Elle contient la librairie qui serra
   * automatiquement importer.
   *
   * @param LayoutBuilderEntityViewDisplay $entity
   */
  function generateStyleFromFieldConfigDisplay(LayoutBuilderEntityViewDisplay $entityView, $saveStyle = false) {
    if ($this->isAdmin && $this->shoMessage)
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via la configuration d'affichage du champs ");
    $display_id = 'default';
    // Si l'utilisateur a activé les layouts.
    $sections = $entityView->getSections();
    if ($sections)
      foreach ($sections as $section) {
        $components = $section->getComponents();
        foreach ($components as $component) {
          $ar = $component->toArray();
          if (!empty($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'])) {
            $id = \str_replace(".", "__", $ar['configuration']['id']);
            $this->addStyleFromModule($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'], $id, $display_id, 'fields', $saveStyle);
          }
        }
      }
    else {
      /**
       * On recupere les champs et on regarde s'il ya des styles à importer.
       */
      $fields = $entityView->get('content');
      foreach ($fields as $field_name => $field) {
        if (!empty($field['settings']['layoutgenentitystyles_view'])) {
          $id = \str_replace(".", "__", $entityView->id() . '-' . $field_name);
          $this->addStyleFromModule($field['settings']['layoutgenentitystyles_view'], $id, $display_id, 'fields', $saveStyle);
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
  function generateStyleFromSection(array $sections, $section_storage_id, $buildThme = true) {
    if ($this->isAdmin && $this->shoMessage)
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via une entité surchargée ");
    $this->libraries[$section_storage_id] = $this->getLibraryForEachSections($sections);
    if ($buildThme)
      $this->addStylesToConfigTheme();
  }
  
  /**
   * Pour les entites surcharger, on ne ferra pas une sauvegarde car données
   * sont dans les entites et si ces entites sont desactivées ou supprimer les
   * styles doit aussi etre supprimer.
   *
   * @param array $sections
   * @param string $section_storage_id
   * @param EntityInterface $entity
   */
  public function generateStyleForFieldsFromEntity(array $sections, $section_storage_id, EntityInterface $entity) {
    $display_id = \str_replace(".", "__", $section_storage_id);
    foreach ($sections as $section) {
      $this->generateStyleForFieldsFromEntitySection($section, $display_id, $entity, true);
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
  protected function generateStyleForFieldsFromEntitySection(Section $section, $display_id, EntityInterface $entity, $themeBuild = true) {
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
   * Certains entites sont ajouté au niveau des blocs cest generalement le cas
   * des block_content.
   */
  protected function loadStyleFromBlocs() {
    $blocks = $this->getCurrentblock();
    /**
     *
     * @var \Drupal\layoutgenentitystyles\Services\ParagraphLoader $paragraph_loader
     */
    $paragraph_loader = \Drupal::service('layoutgenentitystyles.paragraph_loader');
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
          /**
           *
           * @var EntityTypeInterface $entity
           */
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
          if ($entity->getEntityType()->hasKey('bundle')) {
            $view_id = $entity_type_id . '.' . $entity->bundle() . '.default';
          }
          else
            $view_id = $entity_type_id . '.' . $entity_type_id . '.default';
          //
          $entityView = $this->entityTypeManager()->getStorage('entity_view_display')->load($view_id);
          // dump($entity->id(), $view_id);
          // On recupere les styles directements lié à la mise en forme.
          if ($entityView) {
            $this->generateSTyleFromEntity($entityView, false);
            $this->generateStyleFromFieldConfigDisplay($entityView, false);
          }
          $this->getAllStylesFromOverrideEntity($entity);
          
          // On recupere egalement les champs de types references donc la
          // reference est paragraph.
          $entity_auto_generate = [
            $entity_type_id
          ];
          $paragraph_fields = $paragraph_loader->findParagraphReferenceFields($entity_auto_generate);
          if ($paragraph_fields) {
            $paragraph_fields = reset($paragraph_fields);
            foreach ($paragraph_fields as $fielName) {
              /**
               *
               * @var \Drupal\Core\Field\EntityReferenceFieldItemList $FieldItemList
               */
              if ($entity->hasField($fielName)) {
                $values = $entity->get($fielName)->getValue();
                foreach ($values as $value) {
                  $EntityParagraph = $this->entityTypeManager()->getStorage('paragraph')->load($value['target_id']);
                  $paragraph__view_id = 'paragraph.' . $EntityParagraph->bundle() . '.default';
                  $entityView = $this->entityTypeManager()->getStorage('entity_view_display')->load($paragraph__view_id);
                  if ($entityView) {
                    $this->generateSTyleFromEntity($entityView, false);
                    $this->generateStyleFromFieldConfigDisplay($entityView, false);
                  }
                  $this->getAllStylesFromOverrideEntity($EntityParagraph);
                }
              }
            }
          }
        }
      }
    }
  }
  
  /**
   * --
   */
  function getCustomLibrary() {
    $config = $this->ConfigFactory->getEditable('layoutgenentitystyles.settings');
    $list = $config->get('list_style');
    if ($list) {
      $sectionStoragesByLayoutKeys = array_keys($this->sectionStoragesByLayout);
      foreach ($list as $value) {
        /**
         * Tous les styles ne doivent pas etre generer.
         * Cas 1: pour les styles en relations avec une entite( generalement
         * formatage de champs), il faut verifier si l'entite parente (par
         * example : verifier si le paragraphe est present). est presente.
         */
        if (str_contains($value['id'], 'field_block:')) {
          [
            $base_key,
            $entity_id,
            $entity_type
          ] = explode(":", $value['id']);
          $search_key = "$entity_id.$entity_type";
          $has_key = false;
          foreach ($sectionStoragesByLayoutKeys as $key) {
            if (str_contains($key, $search_key)) {
              $has_key = true;
              break;
            }
          }
          if (!$has_key)
            continue;
        }
        $subdir = isset($value['subdir']) ? $value['subdir'] : '';
        $type = !empty($value['type']) ? $value['type'] : 'module';
        $this->addStyleFromView($value['library'], $value['id'], $value['display_id'], $subdir, $type, false);
      }
    }
  }
  
}

