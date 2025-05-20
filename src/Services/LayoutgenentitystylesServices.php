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

class LayoutgenentitystylesServices extends ControllerBase {
  /**
   * Contient la liste des plugins d'affichage.
   *
   * @var array
   */
  protected $sectionStorages = null;
  
  /**
   * The section storage manager.
   *
   * @var SectionStorageManager
   */
  protected $sectionStorageManager;
  
  /**
   */
  protected $LoadStyleFromMod;
  
  /**
   *
   * @var array
   */
  protected $sections = [];
  
  /**
   */
  protected $libraries = [];
  /**
   */
  protected $ConfigFactory;
  
  /**
   * Contient les definitions d'entites qui permettront de generer les styles.
   *
   * @var array
   */
  protected $sectionStoragesByLayout = [];
  
  /**
   * Contient la liste des entites donc on va rechercher s'il possede les
   * données pour le champs "layout_builder__layout"
   * Pour le moment on fait uniquement pour l'ent
   *
   * @var array
   */
  protected $entitiesListLayoutBuilderLayout = [
    'cv_entity'
  ];
  
  /**
   * permet de determiner si l'utilisateur a le role administrator;
   *
   * @var boolean
   */
  private $isAdmin = false;
  
  /**
   * Show message to regenerate theme.
   */
  protected bool $shoMessage = true;
  
  /**
   *
   * @var array
   */
  protected $conf = null;
  
  /**
   *
   * @var ManageFileCustomStyle
   */
  protected $ManageFileCustomStyle;
  
  /**
   *
   * @var ManageFileMailStyle
   */
  protected $ManageFileMailStyle;
  
  //
  // private $container;
  function __construct(SectionStorageManager $SectionStorageManager, LoadStyleFromMod $LoadStyleFromMod, ConfigFactory $ConfigFactory, ManageFileCustomStyle $ManageFileCustomStyle, ManageFileMailStyle $ManageFileMailStyle) {
    $this->sectionStorageManager = $SectionStorageManager;
    $this->LoadStyleFromMod = $LoadStyleFromMod;
    $this->ConfigFactory = $ConfigFactory;
    $this->ManageFileCustomStyle = $ManageFileCustomStyle;
    $this->ManageFileMailStyle = $ManageFileMailStyle;
    // $this->container = \Drupal::getContainer();
    $this->checkIfUserIsAdministrator();
  }
  
  private function checkIfUserIsAdministrator() {
    if (in_array('administrator', $this->currentUser()->getRoles())) {
      $this->isAdmin = true;
    }
  }
  
  public function getConfigFOR_generate_style_theme() {
    if (!$this->conf) {
      $this->conf = $this->ConfigFactory->get('generate_style_theme.settings')->getRawData();
    }
    return $this->conf;
  }
  
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
      $config = $this->ConfigFactory->getEditable('layoutgenentitystyles.settings');
      $entity_auto_generate = array_filter($config->get('entity_auto_generate'), function ($value) {
        return $value ?? false;
      });
      if ($entity_auto_generate) {
        $entity_auto_generate = array_keys($entity_auto_generate);
        $this->sectionStorages = array_filter($DefaultsSectionStorages,
          function ($key) use ($entity_auto_generate) {
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
      foreach ($views as $k => $view) {
        /**
         *
         * @var \Drupal\views\Entity\View $view
         */
        $build = $view->toArray();
        
        if (!empty($build['display'])) {
          foreach ($build['display'] as $display_id => $value) {
            if (!empty($value['display_options']['style']['options']['layoutgenentitystyles_view'])) {
              $this->addStyleFromView($value['display_options']['style']['options']['layoutgenentitystyles_view'], $build['id'], $display_id);
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
    // Timer::start('generateAllFilesStyles');
    $this->loadAllViews();
    $this->sectionStoragesByLayout = $this->getListSectionStorages();
    
    // dd($layout_builder->toArray(), $layout_builder->getTargetBundle());
    foreach ($this->sectionStoragesByLayout as $section_storage => $entityView) {
      /**
       *
       * @var LayoutBuilderEntityViewDisplay $entityView
       */
      $layout_builder = $this->getSectionsForEntityView($section_storage, $entityView);
      $sections = $layout_builder['sections'] ?? [];
      if ($sections) {
        $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
        // $this->getOverrideScss($sections);
      }
      // Si l'entité d'affichage accepte la surcharge et que nous sommes sur le
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
            $key => $bundle
          ]);
        }
        else
          $entities = $this->entityTypeManager()->getStorage($EntityTypeId)->loadByProperties();
        //
        if ($entities) {
          
          foreach ($entities as $entity) {
            if ($entity->hasField('layout_builder__layout')) {
              $sections = [];
              $listSetions = $entity->get('layout_builder__layout')->getValue();
              $section_storage = $entity->getEntityTypeId() . '.' . $entity->bundle() . '.' . $entity->id();
              if ($listSetions) {
                foreach ($listSetions as $value) {
                  // dd($listSetions, $value, reset($value));
                  $sections[] = reset($value);
                }
              }
              
              //
              if ($sections)
                $this->generateStyleForFieldsFromEntity($sections, $section_storage, $entity);
            }
          }
        }
      }
    }
    
    //
    $this->getCustomLibrary();
    // La il ya un soucis, il faut determiner si elle detruit les styles,
    // ajoutées par la configuration surcharger.
    /**
     * Effectivement, elles sont detruite les styles envoyés par l'autre
     * mecanisme.
     */
    // On masque pour le moment.
    // $this->addStylesToConfigTheme(true);
    // il faudra soit separer les sauvegarde au niveau du theme, et ajouté un
    // moyen qui permet de mettre à jours les configirations surcharger.
    $this->addStylesToConfigTheme(true);
    
    // On regenere le fichier custom.
    $this->ManageFileCustomStyle->generateCustomFile();
    // On regenere le fichier custom d'email.
    $this->ManageFileMailStyle->generateCustomFile();
  }
  
  /**
   * Ajout le style apres l'enregistrement d'une view style d'affichage
   * disposant d'une library.
   *
   * @param string $library
   */
  function addStyleFromView(string $library, $id, $display_id, $subdir = '', $type = 'module') {
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
    }
  }
  
  /**
   *
   * @param string $library
   * @param string $id
   * @param string $display_id
   * @param string $subdir
   */
  function addStyleFromModule(string $library, $id, $display_id, $subdir = '') {
    $this->addStyleFromExtention($library, $id, $display_id, $subdir, 'module');
  }
  
  /**
   *
   * @param string $library
   * @param string $id
   * @param string $display_id
   * @param string $subdir
   */
  function addStyleFromTheme(string $library, $id, $display_id, $subdir = 'dynamic_styles') {
    $this->addStyleFromExtention($library, $id, $display_id, $subdir, 'theme');
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
  protected function addStyleFromExtention(string $library, $id, $display_id, $subdir = '', $type = 'module') {
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
   * // Essaie
   * 1- On pourrais sauvegarder cela dans un fichier de configuration
   * specifique.
   * 2- On sauvegarde uniquement les styles ajouter via les modules et les
   * styles liées aux entites ne seront plus sauvegarder. On va parcourrir les
   * entites et recuperer les differents styles ajouté par ces dernieres.
   *
   * @deprecated 2x
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
        $this->addStyleFromView($value['library'], $value['id'], $value['display_id'], $subdir, $type);
      }
    }
  }
  
  /**
   * Genere le style (à partir de la configuration du layout) apres la
   * sauvegarde d'un model de layout.
   *
   * @param LayoutBuilderEntityViewDisplay $entity
   */
  function generateSTyleFromEntity(LayoutBuilderEntityViewDisplay $entity) {
    if ($this->isAdmin)
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via l'entité de configuration ");
    $sections = $entity->getSections();
    $section_storage = $entity->id();
    $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    $this->addStylesToConfigTheme();
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
  function generateStyleFromFieldConfigDisplay(LayoutBuilderEntityViewDisplay $entity) {
    if ($this->isAdmin)
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via la configuration d'affichage du champs ");
    $display_id = 'default';
    // Si l'utilisateur a activé les layouts.
    $sections = $entity->getSections();
    if ($sections)
      foreach ($sections as $section) {
        $components = $section->getComponents();
        foreach ($components as $component) {
          $ar = $component->toArray();
          if (!empty($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'])) {
            $id = \str_replace(".", "__", $ar['configuration']['id']);
            $this->addStyleFromModule($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'], $id, $display_id, 'fields');
          }
        }
      }
    else {
      /**
       * On recupere les champs et on regarde s'il ya des styles à importer.
       */
      $fields = $entity->get('content');
      foreach ($fields as $field_name => $field) {
        if (!empty($field['settings']['layoutgenentitystyles_view'])) {
          $id = \str_replace(".", "__", $entity->id() . '-' . $field_name);
          $this->addStyleFromModule($field['settings']['layoutgenentitystyles_view'], $id, $display_id, 'fields');
        }
      }
    }
  }
  
  /**
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
  function generateStyleFromSection(array $sections, $section_storage_id) {
    if ($this->isAdmin)
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via une entité surchargée ");
    $this->libraries[$section_storage_id] = $this->getLibraryForEachSections($sections);
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
      $components = $section->getComponents();
      foreach ($components as $component) {
        $ar = $component->toArray();
        if (!empty($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'])) {
          $id = \str_replace(".", "__", $ar['configuration']['id']) . ':' . $entity->id();
          $this->addStyleFromFieldsEntitiesOverride($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'], $id, $display_id, 'fields');
        }
      }
    }
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
  protected function addStyleFromFieldsEntitiesOverride(string $library, $id, $display_id, $subdir = '', $type = 'module') {
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
    }
  }
  
  /**
   * Ajoute les styles dans la configuration du theme.
   */
  protected function addStylesToConfigTheme($clean = false) {
    $defaultThemeName = $this->getCurrentTheme();
    $ModuleConf = $this->getConfigFOR_generate_style_theme();
    $conf = \Drupal\generate_style_theme\GenerateStyleTheme::getDynamicConfig($defaultThemeName, $ModuleConf);
    $config = $this->ConfigFactory->getEditable($conf['settings']);
    // Clean datas.
    if ($clean) {
      $config->set('layoutgenentitystyles.scss', []);
      $config->set('layoutgenentitystyles.js', []);
      $config->save();
    }
    //
    foreach ($this->libraries as $section_storage => $libraries) {
      $config->set('layoutgenentitystyles.scss.' . $section_storage, $libraries['scss']);
      $config->set('layoutgenentitystyles.js.' . $section_storage, $libraries['js']);
    }
    $config->save();
    
    // MAJ des fichiers scss et js du theme.
    if (!empty($defaultThemeName)) {
      $ids = $this->entityTypeManager()->getStorage('config_theme_entity')->getQuery()->condition('hostname', $defaultThemeName)->accessCheck(false)->execute();
      
      // dump($defaultThemeName);
      // die();
      if (!empty($ids)) {
        $entity = ConfigThemeEntity::load(reset($ids));
        $GenerateStyleTheme = new GenerateStyleTheme($entity);
        $GenerateStyleTheme->scssFiles();
        $GenerateStyleTheme->jsFiles();
      }
    }
    if ($this->shoMessage)
      $this->messenger()->addStatus(" Vous devez regenerer votre theme ");
  }
  
  public function getCurrentTheme() {
    return \Drupal::config('system.theme')->get('default');
  }
  
  function getLibraries() {
    return $this->libraries;
  }
  
  /**
   * Get information about section..
   */
  protected function getSectionsForEntityView($section_storage, LayoutBuilderEntityViewDisplay $entityView, $section_storage_type = 'defaults') {
    $layout_builder = $entityView->getThirdPartySettings('layout_builder');
    // si l'affichage layout_builder est activé.
    if (!empty($layout_builder['enabled'])) {
      return $layout_builder;
    }
    return [];
    // methode deprecier.
    // if (empty($this->sections[$section_storage])) {
    // $contexts = [];
    // $contexts['display'] = EntityContext::fromEntity($entityView);
    // $sectionStorage =
    // $this->sectionStorageManager->load($section_storage_type, $contexts);
    // $this->sections[$section_storage] = $sectionStorage->getSections();
    // }
    // return $this->sections[$section_storage];
  }
  
  /**
   * Retourne les libraries contenuu dans les sections.
   * ( i.e, retourner les les chemins vers les fichiers js ou scss ).
   *
   * @param array $sections
   */
  protected function getLibraryForEachSections(array $sections) {
    $this->checkIfUserIsAdministrator();
    $libraries = [
      'scss' => [],
      'js' => []
    ];
    
    foreach ($sections as $section) {
      /**
       *
       * @var \Drupal\formatage_models\Plugin\Layout\FormatageModels $plugin
       */
      try {
        $plugin = $this->getPluginForm($section->getLayout());
        $library = $plugin->getPluginDefinition()->getLibrary();
        if (!empty($library)) {
          $subdir = null;
          $path = $plugin->getPluginDefinition()->getPath();
          
          if (str_contains($path, "/layouts/sections/menus"))
            $subdir = 'sections/menus';
          elseif (str_contains($path, "/layouts/sections"))
            $subdir = 'sections';
          elseif (str_contains($path, "/layouts/teasers"))
            $subdir = 'teasers';
          elseif (str_contains($path, "/layouts/sections/headers"))
            $subdir = 'sections/headers';
          elseif (str_contains($path, "/layouts/pages"))
            $subdir = 'pages';
          elseif (str_contains($path, "/layouts/headers"))
            $subdir = 'headers';
          elseif (str_contains($path, "/layouts/footers"))
            $subdir = 'footers';
          else {
            if ($this->isAdmin)
              $this->messenger()->addWarning(' path not found : ' . $path . ' :: ' . $plugin->getPluginId());
          }
          if ($subdir)
            $this->LoadStyleFromMod->getStyle($library, $subdir, $libraries);
        }
        else {
          if ($this->isAdmin)
            $this->messenger()->addWarning(' Library not set :: ' . $plugin->getPluginId());
        }
      }
      catch (\Exception $e) {
        if ($this->isAdmin)
          $this->messenger()->addWarning(" Ce plugin n'existe plus :  " . $section->getLayoutId(), true);
      }
    }
    // dump($libraries);
    return $libraries;
  }
  
  public function setShowMessage($status) {
    $this->shoMessage = $status;
  }
  
  /**
   * Retrieves the plugin form for a given layout.
   *
   * @param \Drupal\Core\Layout\LayoutInterface $layout
   *        The layout plugin.
   *        
   * @return \Drupal\Core\Plugin\PluginFormInterface The plugin form for the
   *         layout.
   */
  protected function getPluginForm(LayoutInterface $layout) {
    if ($layout instanceof PluginWithFormsInterface) {
      return $this->pluginFormFactory->createInstance($layout, 'configure');
    }
    
    if ($layout instanceof PluginFormInterface) {
      return $layout;
    }
    
    throw new \InvalidArgumentException(sprintf('The "%s" layout does not provide a configuration form', $layout->getPluginId()));
  }
}

