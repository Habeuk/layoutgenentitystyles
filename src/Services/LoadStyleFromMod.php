<?php

namespace Drupal\layoutgenentitystyles\Services;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Messenger\MessengerInterface;

/**
 *
 * @author Stephane
 *        
 */
class LoadStyleFromMod {
  /**
   *
   * @var ExtensionPathResolver
   */
  protected $ExtensionPathResolver;
  
  /**
   * The messenger.
   *
   * MessengerInterface
   */
  protected $messenger;
  
  function __construct(ExtensionPathResolver $ExtensionPathResolver, MessengerInterface $messenger) {
    $this->ExtensionPathResolver = $ExtensionPathResolver;
    $this->messenger = $messenger;
  }
  
  /**
   *
   * @param string $library
   * @param string $subdir
   * @return array
   */
  function getStyle($library, $subdir = '', array &$libraries = []) {
    [
      $module,
      $filename
    ] = explode("/", $library);
    if (!empty($module) && $filename) {
      if (!empty($subdir)) {
        $subdir = trim($subdir, "/");
        $subdir .= "/";
      }
      
      $file = DRUPAL_ROOT . '/' . $this->ExtensionPathResolver->getPath('module', $module) . '/wbu-atomique-theme/src/js/' . $subdir . $filename . '.js';
      if (file_exists($file)) {
        $out = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!empty($out)) {
          $scss = [];
          $js = [];
          // les données sont dans un fichier js. on doit remplacer "import "
          // par "@use " et s'assurer que la ligne se termine par '.scss;'
          foreach ($out as $value) {
            if (str_contains($value, '.scss";')) {
              $scss[] = str_replace("import ", "@use ", $value);
            }
            elseif (str_contains($value, '.js";')) {
              $js[] = $value;
            }
          }
          $libraries['scss'][$filename] = $scss;
          $libraries['js'][$filename] = $js;
        }
      }
      else {
        $this->messenger->addWarning('File not exit : ' . $file);
      }
    }
  }
  
}