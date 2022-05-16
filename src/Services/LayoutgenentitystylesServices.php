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
use Drupal\block_content\Entity\BlockContent;
use Drupal\comment\Entity\Comment;

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
  private $container;
  
  function __construct(SectionStorageManager $SectionStorageManager, LoadStyleFromMod $LoadStyleFromMod, ConfigFactory $ConfigFactory) {
    $this->sectionStorageManager = $SectionStorageManager;
    $this->LoadStyleFromMod = $LoadStyleFromMod;
    $this->ConfigFactory = $ConfigFactory;
    $this->container = \Drupal::getContainer();
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
      $config = \Drupal::config('generate_style_theme.settings');
      $conf = $config->getRawData();
      $sectionStorages = [];
      if ($conf['tab1']['use_domain']) {
        // On doit recuperer les entites qui ont un contenu valide.
        // le contenu doit avoir un chamaps field_access valide ou on doit
        // cocher l'option pour tous.
        if (\Drupal::moduleHandler()->moduleExists('domain')) {
          $field_access = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_FIELD;
          $field_all_access = \Drupal\domain_access\DomainAccessManagerInterface::DOMAIN_ACCESS_ALL_FIELD;
          /**
           *
           * @var \Drupal\domain\DomainNegotiator $domain
           */
          $domain = $this->container->get('domain.negotiator');
          foreach ($this->sectionStorages as $key => $value) {
            // dump($key, $value);
            // la clee ($key) est composer de 3 elements.
            [
              $entity_type_id,
              $entity_id,
              $view_mode
            ] = explode(".", $key);
            /**
             *
             * @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $entity_type
             */
            $entity_type = $this->entityTypeManager()->getStorage($entity_type_id);
            if ($entity_type->hasData()) {
              // On cree une instance de la donnée afin de verifier si ce
              // dernier contient un des champs valide.
              // Cette logique de different n'est pas l'ideale, on devrait
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
                  $ids = $entity_type->getQuery()->condition($field_access, $domain->getActiveId())->condition($bundle_key, $entity_id)->execute();
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
   * Permet de generer tous les styles et de les ajouter dans la configuration
   * du theme actif.
   */
  function generateAllFilesStyles() {
    $sectionStorages = $this->getListSectionStorages();
    foreach ($sectionStorages as $section_storage => $entityView) {
      $sections = $this->getSectionsForEntityView($section_storage, $entityView);
      $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    }
    $this->addStylesToConfigTheme();
  }
  
  function generateSTyleFromEntity(LayoutBuilderEntityViewDisplay $entity) {
    $sections = $entity->getSections();
    $section_storage = $entity->id();
    $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    $this->addStylesToConfigTheme();
  }
  
  function generateStyleFromSection(array $sections, $section_storage) {
    $this->libraries[$section_storage] = $this->getLibraryForEachSections($sections);
    $this->addStylesToConfigTheme();
  }
  
  /**
   * Ajoute les styles dans la configuration du theme.
   */
  protected function addStylesToConfigTheme() {
    $defaultThemeName = \Drupal::config('system.theme')->get('default');
    $ModuleConf = \Drupal::config('generate_style_theme.settings')->getRawData();
    $conf = \Drupal\generate_style_theme\GenerateStyleTheme::getDynamicConfig($defaultThemeName, $ModuleConf);
    $config = $this->ConfigFactory->getEditable($conf['settings']);
    //
    foreach ($this->libraries as $section_storage => $libraries) {
      $config->set('layoutgenentitystyles.scss.' . $section_storage, $libraries['scss']);
      $config->set('layoutgenentitystyles.js.' . $section_storage, $libraries['js']);
    }
    $config->save();
    // dump($this->config($defaultThemeName . '.settings')->getRawData());
    $this->messenger()->addStatus("Vous devez regenerer votre theme ");
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
      catch (\Exception $e) {
        
        $this->messenger()->addWarning("Ce plugin n'existe plus :  " . $section->getLayoutId(), true);
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