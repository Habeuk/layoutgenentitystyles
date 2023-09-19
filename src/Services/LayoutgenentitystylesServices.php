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
  public bool $shoMessage = true;
  
  /**
   *
   * @var array
   */
  protected $conf = null;
  /**
   *
   * @var string
   */
  public $domaine_id = null;
  //
  private $container;
  
  function __construct(SectionStorageManager $SectionStorageManager, LoadStyleFromMod $LoadStyleFromMod, ConfigFactory $ConfigFactory) {
    $this->sectionStorageManager = $SectionStorageManager;
    $this->LoadStyleFromMod = $LoadStyleFromMod;
    $this->ConfigFactory = $ConfigFactory;
    $this->container = \Drupal::getContainer();
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
   */
  protected function getListSectionStorages() {
    if (!$this->sectionStorages) {
      
      /**
       * L'entite qui gere les affichages.
       *
       * @var string $entity_type_id
       */
      $entity_type_id = 'entity_view_display';
      $this->sectionStorages = $this->entityTypeManager()->getStorage($entity_type_id)->loadByProperties();
      //
      $conf = $this->getConfigFOR_generate_style_theme();
      $sectionStorages = [];
      if ($conf['tab1']['use_domain']) {
        // On doit recuperer les entites qui ont un contenu valide.
        // le contenu doit avoir un chamaps field_access valide ou on doit
        // cocher l'option pour tous.
        if (\Drupal::moduleHandler()->moduleExists('domain')) {
          $field_access = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD;
          $field_all_access = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_ALL_FIELD;
          $connection = \Drupal::database();
          /**
           *
           * @var \Drupal\domain\DomainNegotiator $domain
           */
          if (!$this->domaine_id) {
            $domain = $this->container->get('domain.negotiator');
            $this->domaine_id = $domain->getActiveId();
          }
          
          foreach ($this->sectionStorages as $key => $value) {
            
            // dump($key);
            // La clee ($key) est composer de 3 elements.
            [
              $entity_type_id,
              $entity_id,
              $view_mode
            ] = explode(".", $key);
            
            // pour les produits, on commence par quelques choses de statiques.
            // if ($entity_id == 'comment') {
            // $entity_type_id = 'commentaire_de_produit';
            // }
            
            /**
             *
             * @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $entity_type
             */
            $entity_type = $this->entityTypeManager()->getStorage($entity_type_id);
            
            if ($entity_type->hasData()) {
              
              // On cree une instance de la donnée afin de verifier si ce
              // dernier contient un des champs valide.
              // Cette logique de differentiation n'est pas l'ideale, on devrait
              // pouvoir determiner si l'entite dispose d'un bundle ou pas.
              if ($entity_type_id != $entity_id) {
                /**
                 *
                 * @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $value
                 */
                $bundle_key = $entity_type->getEntityType()->getKey('bundle');
                $entity = $entity_type->create([
                  $bundle_key => $entity_id
                ]);
                if ($entity->hasField($field_access)) {
                  
                  // On verifie si on a au moins une donnée valide.
                  // On ne peut pas utilisé les entitées pour node, car le
                  // module domain affectue un filtrage.
                  if (str_contains($key, "node.")) {
                    $query = $connection->select('node_field_data', 'nd');
                    $query->addField('nd', 'nid');
                    // $query->condition('nd.status', 1);
                    $query->condition('nd.type', $entity_id);
                    $query->addJoin('INNER', 'node__field_domain_access', 'fda', 'fda.entity_id=nd.nid');
                    $query->condition('fda.field_domain_access_target_id', $this->domaine_id);
                    $results = $query->execute()->fetchAll(\PDO::ATTR_ERRMODE);
                    if (!empty($results)) {
                      $sectionStorages[$key] = $value;
                    }
                  }
                  else {
                    $ids = $entity_type->getQuery()->condition($field_access, $this->domaine_id)->condition($bundle_key, $entity_id)->accessCheck(false)->execute();
                    if ($ids)
                      $sectionStorages[$key] = $value;
                    elseif ($entity->hasField($field_all_access)) {
                      $ids = $entity_type->getQuery()->condition($field_all_access, true)->condition($bundle_key, $entity_id)->accessCheck(false)->execute();
                      if ($ids) {
                        $sectionStorages[$key] = $value;
                        // dump($entity_id);
                      }
                    }
                  }
                } // S'il n'a pas de champs de filtre alors son affichage doit,
                  // etre disponible pour tous les domaines.
                else {
                  $sectionStorages[$key] = $value;
                }
              }
              else {
                $values = [];
                // le type d'entité comment necessite un bundle.
                if ($entity_id == 'comment') {
                  $values['comment_type'] = 'commentaire_de_produit';
                }
                $entity = $entity_type->create($values);
                if ($entity->hasField($field_access)) {
                  // On verifie si on a au moins une donnée valide.
                  $ids = $entity_type->getQuery()->condition($field_access, $this->domaine_id)->accessCheck(false)->execute();
                  if ($ids)
                    $sectionStorages[$key] = $value;
                  elseif ($entity->hasField($field_all_access)) {
                    $ids = $entity_type->getQuery()->condition($field_all_access, true)->accessCheck(false)->execute();
                    if ($ids) {
                      $sectionStorages[$key] = $value;
                      // dump($entity_id);
                    }
                  }
                }
                else {
                  $sectionStorages[$key] = $value;
                  // dump($key);
                }
              }
            }
          }
        }
        $this->sectionStorages = $sectionStorages;
      }
    }
    // dump($this->sectionStorages);
    // die();
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
   * Specifique à wb-horizon.
   */
  protected function getComponentsOverrides() {
    if (\Drupal::moduleHandler()->moduleExists('lesroidelareno')) {
      $fied_access = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD;
      $query = $this->entityTypeManager->getStorage('paragraph')->getQuery();
      $query->condition($fied_access, $this->domaine_id);
      $ids = $query->execute();
      // dump($ids);
      if ($ids) {
        $entities = $this->entityTypeManager->getStorage('paragraph')->loadMultiple($ids);
        foreach ($entities as $entity) {
          if (method_exists($entity, 'hasField')) {
            if ($entity->hasField('layout_builder__layout')) {
              $sections = [];
              $listSetions = $entity->get('layout_builder__layout')->getValue();
              $section_storage = $entity->getEntityTypeId() . '.' . $entity->bundle() . '.' . $entity->id();
              foreach ($listSetions as $value) {
                $sections[] = reset($value);
              }
              $this->generateStyleFromSection($sections, $section_storage);
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
    $this->loadAllViews();
    $sectionStorages = $this->getListSectionStorages();
    $this->getComponentsOverrides();
    foreach ($sectionStorages as $section_storage => $entityView) {
      $sections = $this->getSectionsForEntityView($section_storage, $entityView);
      $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    }
    // On ajoute
    $this->addStyleFromEntitiesOverride();
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
    $this->addStylesToConfigTheme();
  }
  
  /**
   * --
   */
  protected function addStyleFromEntitiesOverride() {
    $conf = $this->getConfigFOR_generate_style_theme();
    if ($conf['tab1']['use_domain']) {
      if (\Drupal::moduleHandler()->moduleExists('domain')) {
        $field_access = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD;
        foreach ($this->entitiesListLayoutBuilderLayout as $entity_type_id) {
          /**
           *
           * @var \Drupal\buildercv\Entity\CvEntity $entity
           */
          $query = $this->entityTypeManager()->getStorage($entity_type_id)->getQuery();
          $query->condition('layout_builder__layout', '', '<>');
          $query->condition($field_access, $this->domaine_id);
          $results = $query->accessCheck(false)->execute();
          if (!empty($results)) {
            $entities = $this->entityTypeManager()->getStorage($entity_type_id)->loadMultiple($results);
            foreach ($entities as $content) {
              /**
               *
               * @var \Drupal\buildercv\Entity\CvEntity $content
               */
              /**
               * *
               *
               * @var \Drupal\layout_builder\Field\LayoutSectionItemList $LayoutField
               */
              $LayoutField = $content->get('layout_builder__layout');
              $sections = $LayoutField->getSections();
              $section_storage = $entity_type_id . '.' . $entity_type_id . '.' . $content->id();
              $this->generateStyleFromSection($sections, $section_storage);
            }
          }
        }
      }
    }
  }
  
  /**
   * Ajout le style apres l'enregistrement d'une view style d'affichage
   * disposant d'une library.
   *
   * @param string $library
   */
  function addStyleFromView(string $library, $id, $display_id, $subdir = '') {
    [
      $module,
      $filename
    ] = explode("/", $library);
    if ($module && $filename) {
      $this->libraries[$module . '.' . $id . '.' . $display_id] = [
        'scss' => [],
        'js' => []
      ];
      $this->LoadStyleFromMod->getStyleDefault($module, $filename, $this->libraries[$module . '.' . $id . '.' . $display_id], $subdir);
      $this->addStylesToConfigTheme();
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
  function addStyleFromModule(string $library, $id, $display_id, $subdir = '') {
    [
      $module,
      $filename
    ] = explode("/", $library);
    if ($module && $filename) {
      $this->libraries[$module . '.' . $id . '.' . $display_id] = [
        'scss' => [],
        'js' => []
      ];
      $this->LoadStyleFromMod->getStyleDefault($module, $filename, $this->libraries[$module . '.' . $id . '.' . $display_id], $subdir);
      $this->addStylesToConfigTheme();
      $this->saveCustomLibrary($library, $id, $display_id, $module, $filename, $subdir);
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
   *
   * @deprecated 2x
   */
  function saveCustomLibrary($library, $id, $display_id, $module, $filename, $subdir) {
    $config = $this->ConfigFactory->getEditable('layoutgenentitystyles.settings');
    $list = $config->get('list_style');
    if (!$list) {
      $list = [];
    }
    $list[$module . '---' . $filename] = [
      'id' => $id,
      'display_id' => $display_id,
      'library' => $library,
      'subdir' => $subdir
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
      foreach ($list as $value) {
        $subdir = isset($value['subdir']) ? $value['subdir'] : '';
        $this->addStyleFromView($value['library'], $value['id'], $value['display_id'], $subdir);
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
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via l'entité de configuration ", true);
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
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via la configuration d'affichage du champs ", true);
    $sections = $entity->getSections();
    foreach ($sections as $section) {
      $components = $section->getComponents();
      foreach ($components as $component) {
        $ar = $component->toArray();
        if (!empty($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'])) {
          $id = \str_replace(".", "__", $ar['configuration']['id']);
          $display_id = 'default';
          $this->addStyleFromModule($ar['configuration']['formatter']['settings']['layoutgenentitystyles_view'], $id, $display_id, 'fields');
        }
      }
    }
  }
  
  /**
   *
   * @param array $sections
   * @param string $section_storage
   *        key of entity (doit contenir deux point par example
   *        cv_entity.cv_entity.150
   */
  function generateStyleFromSection(array $sections, $section_storage) {
    if ($this->isAdmin)
      \Drupal::messenger()->addStatus(" Les styles (scss/js) maj via une entité surchargée ", true);
    $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    $this->addStylesToConfigTheme();
  }
  
  /**
   * Ajoute les styles dans la configuration du theme.
   */
  protected function addStylesToConfigTheme($clean = false) {
    if (!$this->domaine_id) {
      $defaultThemeName = \Drupal::config('system.theme')->get('default');
    }
    else
      $defaultThemeName = $this->domaine_id;
    
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
  
  function getLibraries() {
    return $this->libraries;
  }
  
  /**
   * Recupere les sections pour un model d'affichage données.
   */
  protected function getSectionsForEntityView($section_storage, LayoutBuilderEntityViewDisplay $entityView, $section_storage_type = 'defaults') {
    if (empty($this->sections[$section_storage])) {
      $contexts = [];
      $contexts['display'] = EntityContext::fromEntity($entityView);
      $sectionStorage = $this->sectionStorageManager->load($section_storage_type, $contexts);
      $this->sections[$section_storage] = $sectionStorage->getSections();
    }
    return $this->sections[$section_storage];
  }
  
  /**
   * Retourne les libraries contenuu dans les sections.
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
      // dump($section);
      /**
       *
       * @var \Drupal\layout_builder\Section $section
       */
      
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
