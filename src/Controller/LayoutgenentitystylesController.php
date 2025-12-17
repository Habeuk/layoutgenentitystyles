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
use Drupal\layoutgenentitystyles\Services\BuildStylesByEntities;
use Drupal\Component\Serialization\Json;
use Drupal\generate_style_theme\Services\Reposotories\GenerateFiles;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns responses for layout generate entity styles routes.
 */
class LayoutgenentitystylesController extends ControllerBase {
  /**
   * to be able to lunch npm run {Dev,Prod} from here
   */
  use GenerateFiles;
  
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
  /**
   *
   * @var BuildStylesByEntities
   */
  protected $buildStylesByEntities;
  
  function __construct(LayoutgenentitystylesServices $LayoutgenentitystylesServices, BuildStylesByEntities $buildStylesByEntities) {
    $this->LayoutgenentitystylesServices = $LayoutgenentitystylesServices;
    $this->buildStylesByEntities = $buildStylesByEntities;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('layoutgenentitystyles.add.style.theme'), $container->get('layoutgenentitystyles.add.styles.by.entities'));
  }
  
  /**
   *
   * @deprecated
   * @return string[]
   */
  public function ManuelGenerateAll() {
    $this->LayoutgenentitystylesServices->getComponentsOverrides();
    return $this->ManuelGenerate();
  }
  
  /**
   *
   * @param string $entity_type_id
   * @param string $entity_id
   * @return string[]|string[][]|string[][][]|array[][]
   */
  public function ManuelGenerateIndividualEntity($entity_type_id, $entity_id) {
    $items = [];
    $entity = $this->entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
    $this->buildStylesByEntities->generateFileForEntity($entity);
    $lists = [
      '#type' => 'html_tag',
      '#tag' => 'ol',
      '#attributes' => [
        'style' => ''
      ],
      $items
    ];
    $build['content'] = [
      '#type' => 'item',
      '#markup' => "Les styles ont été MAJ.",
      $lists
    ];
    //
    return $build;
  }
  
  /**
   *
   * @deprecated to delete.
   * @return string[]
   */
  public function ManuelGenerate() {
    $this->LayoutgenentitystylesServices->generateAllFilesStyles();
    $this->messenger()->addStatus(" Style maj, vous devez regerener les fichiers du theme. ");
    $librairies = $this->LayoutgenentitystylesServices->getLibraries();
    $items = [];
    foreach ($librairies as $section_storage => $librairy) {
      $fgt = [];
      if (empty($librairy['scss']) && empty($librairy['js']))
        continue;
      foreach ($librairy as $k => $librairy_style) {
        
        foreach ($librairy_style as $pluginId => $files) {
          if (!empty($files)) {
            $fgt[] = [
              '#type' => 'html_tag',
              '#tag' => 'strong',
              '#value' => $k . ' :: ' . $pluginId
            ];
            foreach ($files as $file) {
              $fgt[] = [
                '#type' => 'html_tag',
                '#tag' => 'div',
                '#value' => $file
              ];
            }
          }
        }
      }
      
      $items[] = [
        '#type' => 'html_tag',
        '#tag' => 'li',
        [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $section_storage
        ],
        [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => [
              ''
            ],
            'style' => 'margin-bottom:30px; '
          ],
          $fgt
        ]
      ];
    }
    $lists = [
      '#type' => 'html_tag',
      '#tag' => 'ol',
      '#attributes' => [
        'style' => ''
      ],
      $items
    ];
    $build['content'] = [
      '#type' => 'item',
      '#markup' => "Les styles ont été MAJ.",
      $lists
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
  
  /**
   *
   * @param array|string $configs
   * @param number $code
   * @param string $message
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  protected function reponse($configs, $code = null, $message = null) {
    if (!is_string($configs))
      $configs = Json::encode($configs);
    $reponse = new JsonResponse();
    if ($code)
      $reponse->setStatusCode($code, $message);
    $reponse->setContent($configs);
    return $reponse;
  }
  
  /**
   *
   * @todo
   */
  // ///////////////////////////////////////////////////////////////////////////
  // ///////////////////////////////////////////////////////////////////////////
  public function ManuelGenerateByEntities() {
    $this->buildStylesByEntities->generateAllFilesStyles();
    // Rendus fait buguer la page, car il utilise bcp de ram pour les sites
    // ayant bcp de contenu.
    // @todo voir comment gerer cela.
    $pages = []; // $this->buildStylesByEntities->getPages();
    $pageId = 1;
    $accordion = [];
    
    foreach ($pages as $pageKey => $pageContent) {
      
      $accordion[$pageKey] = [
        '#type' => 'details',
        '#title' => $pageId . ' : ' . $pageKey,
        '#open' => FALSE,
        'content' => [
          'tree' => $this->buildListRecursive($pageContent)
        ]
      ];
      $pageId++;
    }
    
    return [
      '#type' => 'container',
      'msg' => [
        '#markup' => '<p>Les styles ont été mis à jour.</p>'
      ],
      'accordion' => $accordion
    ];
  }
  
  private function buildListRecursive($data) {
    // Si texte simple
    if (is_string($data)) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'li',
        '#value' => $data
      ];
    }
    
    // Si vide
    if (empty($data)) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'li',
        '#value' => '(vide)'
      ];
    }
    
    // Création de la liste
    $list = [
      '#type' => 'html_tag',
      '#tag' => 'ul',
      'children' => []
    ];
    
    foreach ($data as $key => $value) {
      
      // Cas : liste d’assets (strings)
      if (is_array($value) && $this->isStringList($value)) {
        $subList = [
          '#type' => 'html_tag',
          '#tag' => 'ul',
          'children' => []
        ];
        
        foreach ($value as $v) {
          $subList['children'][] = [
            '#type' => 'html_tag',
            '#tag' => 'li',
            '#value' => $v
          ];
        }
        
        $list['children'][] = [
          '#type' => 'html_tag',
          '#tag' => 'li',
          'children' => [
            [
              '#type' => 'html_tag',
              '#tag' => 'strong',
              '#value' => $key
            ],
            $subList
          ]
        ];
        continue;
      }
      
      // Cas récursif : sous-nœud complexe
      $list['children'][] = [
        '#type' => 'html_tag',
        '#tag' => 'li',
        'children' => [
          [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => $key
          ],
          $this->buildListRecursive($value)
        ]
      ];
    }
    
    return $list;
  }
  
  private function isStringList(array $array): bool {
    foreach ($array as $v) {
      if (!is_string($v)) {
        return false;
      }
    }
    return true;
  }
}
