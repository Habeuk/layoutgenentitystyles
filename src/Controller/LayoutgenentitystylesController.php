<?php

namespace Drupal\layoutgenentitystyles\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\layout_builder\SectionStorage\SectionStorageManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_builder\Plugin\SectionStorage\DefaultsSectionStorage;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\layoutgenentitystyles\Services\LayoutgenentitystylesServices;

/**
 * Returns responses for layout generate entity styles routes.
 */
class LayoutgenentitystylesController extends ControllerBase {
  
  /**
   * The section storage manager.
   *
   * @var SectionStorageManager
   */
  protected $sectionStorageManager;
  
  /**
   * The section storage.
   *
   * @var DefaultsSectionStorage
   */
  protected $sectionStorage;
  /**
   */
  protected $LayoutgenentitystylesServices;
  
  function __construct(LayoutgenentitystylesServices $LayoutgenentitystylesServices) {
    $this->LayoutgenentitystylesServices = $LayoutgenentitystylesServices;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('layoutgenentitystyles.add.style.theme'));
  }
  
  /**
   * Builds the response.
   */
  public function build() {
    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!')
    ];
    
    return $build;
  }
  
  public function ManuelGenerate() {
    $this->LayoutgenentitystylesServices->generateAllFilesStyles();
    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t(' It works! ManuelGenerate ')
    ];
    //
    return $build;
  }
  
  /**
   *
   * @return string[]|\Drupal\Core\StringTranslation\TranslatableMarkup[]
   */
  public function ManuelGenerate0() {
    $entity_type_id = 'entity_view_display';
    $section_storage_type = 'defaults';
    $section_storage = 'node.dynamic_home_page.default';
    
    /**
     *
     * @var \Drupal\Core\Entity\Entity\EntityViewDisplay $ConfigEntityType
     */
    $ConfigEntityType = $this->entityTypeManager()->getStorage($entity_type_id);
    
    $entityView = $ConfigEntityType->load($section_storage);
    
    $contexts = [];
    $contexts['display'] = EntityContext::fromEntity($entityView);
    $this->sectionStorage = $this->sectionStorageManager->load($section_storage_type, $contexts);
    $sections = $this->sectionStorage->getSections();
    
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
    
    //
    if (!empty($libraries)) {
      $defaultThemeName = \Drupal::config('system.theme')->get('default');
      // dump($defaultThemeName);
      /**
       *
       * @var \Drupal\Core\Config\Config $config
       */
      $config = \Drupal::service('config.factory')->getEditable($defaultThemeName . '.settings');
      $config->set('layoutgenentitystyles.scss.' . $section_storage, $libraries['scss']);
      $config->set('layoutgenentitystyles.js.' . $section_storage, $libraries['js']);
      // $config->delete();
      $config->save();
    }
    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t(' It works! ManuelGenerate ')
    ];
    //
    return $build;
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
