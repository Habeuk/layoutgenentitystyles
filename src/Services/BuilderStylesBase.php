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
use Drupal\Core\Entity\EntityFieldManager;
use Stephane888\Debug\Repositories\ConfigDrupal;

class BuilderStylesBase extends ControllerBase {
  
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
  protected $isAdmin = false;
  
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
  
  /**
   *
   * @var \Drupal\layout_custom_style\StyleScssPluginManager
   */
  protected $StyleScssPlugin;
  
  /**
   *
   * @var EntityFieldManager
   */
  protected $EntityFieldManager;
  /**
   * contient les champs references regourpé par type d'entité.
   *
   * @var array
   */
  private $ReferenceFields = [];
  
  /**
   * Default config ( config for layoutgenentitystyles ).
   *
   * @var array
   */
  private $configs = [];
  
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
  
  protected function checkIfUserIsAdministrator() {
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
   * Si on activé layout builder sur cette entite, alors on renvoit les sections
   * et d'autres informations, sinon on renvoit un array vide.
   */
  protected function getSectionsForEntityView(LayoutBuilderEntityViewDisplay $entityView) {
    $layout_builder = $entityView->getThirdPartySettings('layout_builder');
    // Si l'affichage layout_builder est activé.
    if (!empty($layout_builder['enabled'])) {
      return $layout_builder;
    }
    return [];
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
      }
      catch (\Exception $e) {
        if ($this->isAdmin)
          $this->messenger()->addWarning(" Ce plugin n'existe plus :  " . $section->getLayoutId(), true);
      }
    }
    return $libraries;
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
  protected function addStyleFromFieldsEntitiesOverride(string $library, $id, $display_id, $subdir = '', $type = 'module', $themeBuild = true) {
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
      if ($themeBuild)
        $this->addStylesToConfigTheme();
    }
  }
  
  /**
   * Recupere le theme par defaut.
   *
   * @return string
   */
  public function getDefaultTheme() {
    return \Drupal::config('system.theme')->get('default');
  }
  
  function getLibraries() {
    return $this->libraries;
  }
  
  /**
   *
   * @return array
   */
  public function getConfigs(): array {
    if (!$this->configs) {
      $this->configs = ConfigDrupal::config('layoutgenentitystyles.settings');
    }
    return $this->configs;
  }
  
  public function setShowMessage($status) {
    $this->shoMessage = $status;
  }
  
  /**
   *
   * @return \Drupal\Core\Entity\EntityFieldManager
   */
  public function getEntityFieldManager() {
    if (!$this->EntityFieldManager) {
      $this->EntityFieldManager = \Drupal::service('entity_field.manager');
    }
    return $this->EntityFieldManager;
  }
  
  /**
   *
   * @param string $entityTypeId
   * @param string $bundle
   * @return array
   */
  protected function getReferenceFields(string $entityTypeId, string $bundle, $EntityLayoutOverride = false): array {
    $configs = $this->getConfigs();
    if (empty($this->ReferenceFields[$entityTypeId . $bundle])) {
      $entityFieldManager = $this->getEntityFieldManager();
      $fieldDefinitions = $entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
      $referenceFields = [];
      foreach ($fieldDefinitions as $fieldName => $fieldDefinition) {
        if ($fieldDefinition->getType() === 'entity_reference' || $fieldDefinition->getType() === 'entity_reference_revisions') {
          $view = $fieldDefinition->getDisplayOptions('view');
          if (!empty($view) && !empty($configs['entity_auto_generate'][$fieldDefinition->getSetting('target_type')])) {
            $referenceFields[$fieldName] = [
              'field_name' => $fieldDefinition->getName(),
              'field_label' => $fieldDefinition->getLabel(),
              'field_settings' => $fieldDefinition->getSettings(),
              'field_display_view' => $view,
              'layout_display' => false // il faudra determiner plus tard si le
                                        // champs est disponible en affichage.
            ];
          }
        }
      }
      $this->ReferenceFields[$entityTypeId . $bundle] = $referenceFields;
    }
    return $this->ReferenceFields[$entityTypeId . $bundle];
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