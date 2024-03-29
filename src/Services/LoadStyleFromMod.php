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
   * Recupere les styles à partir d'une library.
   * Example de library : buildercv/time-line
   *
   * @param string $library
   * @param string $subdir
   * @return array
   */
  function getStyle(string $library, $subdir = '', array &$libraries = []) {
    [
      $module,
      $filename
    ] = explode("/", $library);
    if (!empty($module) && $filename) {
      $this->getStyleDefault($module, $filename, $libraries, $subdir);
    }
  }
  
  /**
   * Recupere le style à partir de n'importe quel module.
   * Les styles doivent etre definie dans :
   * {$module}/wbu-atomique-theme/src/js/{$filename}.js
   *
   * @param string $module
   *        le nom du module drupal.
   * @param string $filename
   *        le nom exact de la library
   * @param array $libraries
   * @param string $subdir
   *        s'il faut acceder à un sous-repertoire, le presisé.
   *        example : sections/headers
   */
  function getStyleDefault(string $module, string $filename, array &$libraries = [], string $subdir = '') {
    if (!empty($subdir)) {
      $subdir = trim($subdir, "/");
      $subdir .= "/";
    }
    $file = DRUPAL_ROOT . '/' . $this->ExtensionPathResolver->getPath('module', $module) . '/wbu-atomique-theme/src/js/' . $subdir . $filename . '.js';
    if (file_exists($file)) {
      $this->readFile($filename, $file, $libraries);
    }
    else {
      $this->messenger->addWarning($module . ', File not exit : ' . $file);
    }
  }
  
  /**
   *
   * @param string $filename
   * @param string $file
   * @param string $libraries
   */
  private function readFile($filename, $file, &$libraries) {
    $out = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($out)) {
      $scss = [];
      $js = [];
      // les données sont dans un fichier js. on doit remplacer "import "
      // par "@use " et s'assurer que la ligne se termine par '.scss";' ou
      // '.js";'
      foreach ($out as $value) {
        $value = str_replace("'", '"', $value);
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
  
}