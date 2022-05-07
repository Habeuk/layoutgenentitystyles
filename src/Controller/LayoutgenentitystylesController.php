<?php

namespace Drupal\layoutgenentitystyles\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for layout generate entity styles routes.
 */
class LayoutgenentitystylesController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function build() {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

}
