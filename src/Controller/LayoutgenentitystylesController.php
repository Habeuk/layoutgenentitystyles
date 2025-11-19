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

  public function ManuelGenerateAll() {
    $this->LayoutgenentitystylesServices->getComponentsOverrides();
    return $this->ManuelGenerate();
  }

  public function ManuelGenerateByEntities() {
    $items = [];
    $this->buildStylesByEntities->generateAllFilesStyles();
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
}
