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
  
}