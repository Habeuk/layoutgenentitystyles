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
  
  protected function getConfigFOR_generate_style_theme() {
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
   * ( i.e, retourner les chemins vers les fichiers js ou scss ).
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
  protected function getDefaultTheme() {
    return \Drupal::config('system.theme')->get('default');
  }
  
  function getLibraries() {
    return $this->libraries;
  }
  
  /**
   *
   * @return array
   */
  protected function getConfigs(): array {
    if (!$this->configs) {
      $this->configs = ConfigDrupal::config('layoutgenentitystyles.settings');
    }
    return $this->configs;
  }
  
  protected function setShowMessage($status) {
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
   * Retourne un tableau avec les informations sur l'entité et le bundle à
   * construire.
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
  
  protected function getCurrentblock() {
    $defaultThemeName = $this->getDefaultTheme();
    return \Drupal::entityTypeManager()->getStorage('block')->loadByProperties([
      'theme' => $defaultThemeName,
      'status' => 1
    ]);
  }
  
  /**
   * Ajoute les styles dans la configuration du theme.
   */
  protected function addStylesToConfigTheme(bool $clean = false, array $customStyles = [], bool $saveInTheme = false) {
    $defaultThemeName = $this->getDefaultTheme();
    $ModuleConf = $this->getConfigFOR_generate_style_theme();
    // MAJ des fichiers scss et js du theme.
    if (!empty($defaultThemeName)) {
      $conf = \Drupal\generate_style_theme\GenerateStyleTheme::getDynamicConfig($defaultThemeName, $ModuleConf);
      $config = $this->ConfigFactory->getEditable($conf['settings']);
      /**
       * La sauvegarde dans le theme, n'est pas une bonne idée, cela augmente
       * les données de configs qui sont chargées en memoire ou en cache.
       * // on supprime et on vide le theme. *
       */
      if ($saveInTheme) {
        if ($clean) {
          $config->set('layoutgenentitystyles.scss', []);
          $config->set('layoutgenentitystyles.js', []);
          $config->save();
        }
        foreach ($this->libraries as $section_storage => $libraries) {
          $config->set('layoutgenentitystyles.scss.' . $section_storage, $libraries['scss']);
          $config->set('layoutgenentitystyles.js.' . $section_storage, $libraries['js']);
        }
        $config->save();
      }
      // @todo à supprimer à la version 6.x ( le temps de nettoyer les configs
      // de themes ).
      else {
        $config->clear('layoutgenentitystyles');
        $config->save();
      }
      //
      $ids = $this->entityTypeManager()->getStorage('config_theme_entity')->getQuery()->condition('hostname', $defaultThemeName)->accessCheck(false)->execute();
      if (!empty($ids)) {
        // importe les styles customs definit pour toutes les pages.
        $styles = $this->ManageFileCustomStyle->generateGlobalestyles();
        $customStyles['generate_style_theme.styles.custom'] = [
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
        $entity = ConfigThemeEntity::load(reset($ids));
        $GenerateStyleTheme = new GenerateStyleTheme($entity);
        $librairiesStyles = $this->getArrayScssJs($this->libraries);
        $customsStyles = $this->getArrayScssJs($customStyles);
        $GenerateStyleTheme->scssFiles($librairiesStyles['scss'], $customsStyles['scss']);
        $GenerateStyleTheme->jsFiles($librairiesStyles['js'], $customsStyles['js']);
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
   * Ajout le style apres l'enregistrement d'une view style d'affichage
   * disposant d'une library.
   *
   * @param string $library
   */
  protected function addStyleFromView(string $library, $id, $display_id, $subdir = '', $type = 'module', $themeBuild = false) {
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
      // if ($themeBuild)
      // $this->addStylesToConfigTheme();
    }
  }
  
}