<?php

namespace Drupal\flysystem_objective\Plugin\EntityBrowser\Widget;

use Drupal\entity_browser\WidgetBase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_browser\WidgetValidationManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Views;


/**
 * Reference a remote file using remote stream wrapper.
 *
 * @EntityBrowserWidget(
 *   id = "directory_listing",
 *   label = @Translation("Directory Listing"),
 *   description = @Translation("Directory Listing From Objective")
 * )
 */
class ViewEntityBrowser extends WidgetBase {

  /** @var \Drupal\Core\Session\AccountProxyInterface */
  protected $currentUser;

  /**
   * RemoteStream constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\entity_browser\WidgetValidationManager $validation_manager
   *   The Widget Validation Manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EventDispatcherInterface $event_dispatcher, EntityTypeManagerInterface $entity_type_manager, WidgetValidationManager $validation_manager, AccountProxyInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $event_dispatcher, $entity_type_manager, $validation_manager);
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_browser.widget_validation'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'url_field_title' => $this->t('URL to file'),
      'submit_text' => $this->t('Select file'),
      'view_name' => $this->t('directory_listing_view'),
      'view_display_id'=> $this->t('page_1'),

    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(array &$original_form, FormStateInterface $form_state, array $additional_widget_parameters) {
    $form = parent::getForm($original_form, $form_state, $additional_widget_parameters);
      $view = Views::getView($this->configuration['view_name']);
     // Set which view display we want.
      $view->setDisplay('page_1');
     // Execute the view.
      $view->execute();
      $content = $view->buildRenderable($this->configuration['view_display_id']);
      $form['view_content'] = [
          '#type' => 'item',
          '#title' => '',
          '#description' => $content,
          '#default_value' => $content,
      ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntities(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $form_state->getValue('url'),
      'uid' => $this->currentUser->id(),
    ]);

    $file->setPermanent();

    return [$file];
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array &$element, array &$form, FormStateInterface $form_state) {
    if (!empty($form_state->getTriggeringElement()['#eb_widget_main_submit'])) {
      $files = $this->prepareEntities($form, $form_state);
      foreach ($files as $file) {
        $file->save();
      }
      $this->selectEntities($files, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['view_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('View Name'),
      '#default_value' => $this->configuration['view_name'],
      '#required' => TRUE,
    ];
      $form['view_display_id'] = [
          '#type' => 'textfield',
          '#title' => $this->t('View Display Id'),
          '#default_value' => $this->configuration['view_display_id'],
          '#required' => TRUE,
      ];
/*    $form['submit_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submit button text'),
      '#default_value' => $this->configuration['submit_text'],
      '#required' => TRUE,
    ];*/
    return $form;
  }




}
