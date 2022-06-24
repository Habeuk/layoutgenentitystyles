<?php

namespace Drupal\layoutgenentitystyles\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure layout generate entity styles settings for this site.
 */
class SettingsForm extends ConfigFormBase {
  
  /**
   *
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'layoutgenentitystyles_settings';
  }
  
  /**
   *
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'layoutgenentitystyles.settings'
    ];
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('layoutgenentitystyles.settings')->getRawData();
    $form['list_style'] = [
      '#type' => 'details',
      '#title' => 'Contient les styles ajouter par des modules',
      '#open' => false,
      '#tree' => true
    ];
    //
    if (!empty($config['list_style'])) {
      foreach ($config['list_style'] as $module_name => $style) {
        $form['list_style'][$module_name] = [
          '#type' => 'details',
          '#title' => $module_name,
          '#open' => true
        ];
        $form['list_style'][$module_name]['library'] = [
          '#type' => 'textfield',
          '#title' => 'library',
          '#default_value' => $style['library']
        ];
        $form['list_style'][$module_name]['display_id'] = [
          '#type' => 'textfield',
          '#title' => 'display_id',
          '#default_value' => $style['display_id']
        ];
        $form['list_style'][$module_name]['id'] = [
          '#type' => 'textfield',
          '#title' => 'id',
          '#default_value' => $style['id']
        ];
      }
    }
    //
    return parent::buildForm($form, $form_state);
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('layoutgenentitystyles.settings')->set('list_style', $form_state->getValue('list_style'))->save();
    parent::submitForm($form, $form_state);
  }
  
}
