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
  
  public function getConfigs() {
    return \Drupal::config('layoutgenentitystyles.settings')->getRawData();
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