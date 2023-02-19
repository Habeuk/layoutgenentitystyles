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
    //
    $form['enabled_auto_generate_config'] = [
      '#type' => 'checkbox',
      '#title' => "activé l'autoregenration des fichiers scss & js pour les entites de onfigurations ",
      '#default_value' => isset($config['enabled_auto_generate_config']) ? $config['enabled_auto_generate_config'] : 1
    ];
    //
    $form['enabled_auto_generate_fieldconfig'] = [
      '#type' => 'checkbox',
      '#title' => "activé l'autoregenration des fichiers scss & js pour l'affichage des champss ",
      '#default_value' => isset($config['enabled_auto_generate_fieldconfig']) ? $config['enabled_auto_generate_fieldconfig'] : 1
    ];
    //
    $form['enabled_auto_generate_entity'] = [
      '#type' => 'checkbox',
      '#title' => "activé l'autoregenration des fichiers scss & js pour les entites surcharger",
      '#default_value' => isset($config['enabled_auto_generate_entity']) ? $config['enabled_auto_generate_entity'] : 1
    ];
    //
    $form['entity_auto_generate'] = [
      '#type' => 'details',
      '#title' => 'Contient les entites qui doivent etre automatiquement genere',
      '#open' => false,
      '#tree' => true
    ];
    
    $entities = \Drupal::entityTypeManager()->getDefinitions();
    foreach ($entities as $entity) {
      $form['entity_auto_generate'][$entity->id()] = [
        '#type' => 'checkbox',
        '#title' => $entity->getLabel(),
        '#default_value' => isset($config['list_entities.' . $entity->id()]) ? $config['list_entities.' . $entity->id()] : 0
      ];
    }
    //
    $form['list_style'] = [
      '#type' => 'details',
      '#title' => 'Contient les styles ajouter par des modules',
      '#open' => false,
      '#tree' => true
    ];
    if (!empty($config['list_style'])) {
      foreach ($config['list_style'] as $module_name => $style) {
        $form['list_style'][$module_name] = [
          '#type' => 'details',
          '#title' => $module_name . ' => ' . $style['library'],
          '#open' => false
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
        $form['list_style'][$module_name]['subdir'] = [
          '#type' => 'textfield',
          '#title' => 'subdir',
          '#default_value' => isset($style['subdir']) ? $style['subdir'] : ''
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
    $config = $this->config('layoutgenentitystyles.settings');
    $config->set('list_style', $form_state->getValue('list_style'));
    $config->set('entity_auto_generate', $form_state->getValue('entity_auto_generate'));
    $config->set('enabled_auto_generate_config', $form_state->getValue('enabled_auto_generate_config'));
    $config->set('enabled_auto_generate_entity', $form_state->getValue('enabled_auto_generate_entity'));
    $config->set('enabled_auto_generate_fieldconfig', $form_state->getValue('enabled_auto_generate_fieldconfig'));
    $config->save();
    parent::submitForm($form, $form_state);
  }
  
}
