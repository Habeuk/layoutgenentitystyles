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
use phpDocumentor\Reflection\Types\This;
use Drupal\Core\Config\ConfigFactory;

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
  
  function __construct(SectionStorageManager $SectionStorageManager, LoadStyleFromMod $LoadStyleFromMod, ConfigFactory $ConfigFactory) {
    $this->sectionStorageManager = $SectionStorageManager;
    $this->LoadStyleFromMod = $LoadStyleFromMod;
    $this->ConfigFactory = $ConfigFactory;
  }
  
  /**
   * On recupere la liste des plugins d'affichage d'entite.
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
    }
    return $this->sectionStorages;
  }
  
  /**
   * Permet de generer tous les styles et de les ajouter dans la configuration du theme actif.
   */
  function generateAllFilesStyles() {
    $sectionStorages = $this->getListSectionStorages();
    foreach ($sectionStorages as $section_storage => $entityView) {
      $sections = $this->getSectionsForEntityView($section_storage, $entityView);
      $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    }
    $this->addStylesToConfigTheme();
  }
  
  /**
   * Ajoute les styles dans la configuration du theme.
   */
  protected function addStylesToConfigTheme() {
    $defaultThemeName = \Drupal::config('system.theme')->get('default');
    $config = $this->ConfigFactory->getEditable($defaultThemeName . '.settings');
    // dump($this->libraries);
    foreach ($this->libraries as $section_storage => $libraries) {
      $config->set('layoutgenentitystyles.scss.' . $section_storage, $libraries['scss']);
      $config->set('layoutgenentitystyles.js.' . $section_storage, $libraries['js']);
    }
    $config->save();
    // dump($this->config($defaultThemeName . '.settings')->getRawData());
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
      /**
       *
       * @var \Drupal\formatage_models\Plugin\Layout\FormatageModels $plugin
       */
      $plugin = $this->getPluginForm($section->getLayout());
      $library = $plugin->getPluginDefinition()->getLibrary();
      if (!empty($library)) {
        $subdir = '';
        $path = $plugin->getPluginDefinition()->getPath();
        if (strpos($path, "sections") !== FALSE)
          $subdir = 'sections';
        elseif (strpos($path, "teasers") !== FALSE)
          $subdir = 'teasers';
        //
        $this->LoadStyleFromMod->getStyle($library, $subdir, $libraries);
      }
    }
    return $libraries;
  }
  
  /**
   * Retrieves the plugin form for a given layout.
   *
   * @param \Drupal\Core\Layout\LayoutInterface $layout
   *        The layout plugin.
   *        
   * @return \Drupal\Core\Plugin\PluginFormInterface The plugin form for the layout.
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