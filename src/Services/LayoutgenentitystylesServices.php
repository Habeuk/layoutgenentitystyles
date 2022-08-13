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
use phpDocumentor\Reflection\Types\This;

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
            
            // dump($key, $value);
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
                    $ids = $entity_type->getQuery()->condition($field_access, $this->domaine_id)->condition($bundle_key, $entity_id)->execute();
                    if ($ids)
                      $sectionStorages[$key] = $value;
                    elseif ($entity->hasField($field_all_access)) {
                      $ids = $entity_type->getQuery()->condition($field_all_access, true)->condition($bundle_key, $entity_id)->execute();
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
                  $ids = $entity_type->getQuery()->condition($field_access, $this->domaine_id)->execute();
                  if ($ids)
                    $sectionStorages[$key] = $value;
                  elseif ($entity->hasField($field_all_access)) {
                    $ids = $entity_type->getQuery()->condition($field_all_access, true)->execute();
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
        if ($k == 'hero') {
          // dump($build);
        }
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
    $this->loadAllViews();
    $sectionStorages = $this->getListSectionStorages();
    
    foreach ($sectionStorages as $section_storage => $entityView) {
      $sections = $this->getSectionsForEntityView($section_storage, $entityView);
      $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    }
    
    $this->getCustomLibrary();
    $this->addStylesToConfigTheme(true);
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
   * Ajout le style apres l'enregistrement d'une view style d'affichage
   * disposant d'une library, ou tout autre module.
   * SI on regenere les styles on a perd ces styles.
   * On va les ajoutés dans une variable pour le momment, apres on verra comment
   * les gerer de maniere dynamique.
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
   * Sauvegarde un style custom de maniere permanente.
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
   * Genere le style apres la sauvegarde d'un model par defaut de layout.
   *
   * @param LayoutBuilderEntityViewDisplay $entity
   */
  function generateSTyleFromEntity(LayoutBuilderEntityViewDisplay $entity) {
    $sections = $entity->getSections();
    $section_storage = $entity->id();
    $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    $this->addStylesToConfigTheme();
  }
  
  /**
   *
   * @param array $sections
   * @param string $section_storage
   */
  function generateStyleFromSection(array $sections, $section_storage) {
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
      $ids = $this->entityTypeManager()->getStorage('config_theme_entity')->getQuery()->condition('hostname', $defaultThemeName)->execute();
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