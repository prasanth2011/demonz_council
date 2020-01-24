<?php

// namespace Drupal\filebrowser\Form;

namespace Drupal\flysystem_objective\Form;

use Drupal\entity_browser\Events\Events;
use Drupal\entity_browser\WidgetBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\Core\Ajax\AfterCommand;
use Drupal\Core\Ajax\AjaxResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Ajax\AlertCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\entity_browser\WidgetValidationManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\entity_browser\Events\EntitySelectionEvent;
use Drupal\filebrowser;

class FileForm extends ConfirmFormBase {
    /** @var \Drupal\Core\Session\AccountProxyInterface */
    protected $currentUser;
    /**
     * @var int
     */
    protected $queryFid;

    /**
     * @var \Drupal\node\NodeInterface
     */
    protected $node;

    /**
     * Common methods
     * @var \Drupal\filebrowser\Services\Common
     */
    protected $common;

    /**
     * Validator methods
     *
     * @var \Drupal\filebrowser\Services\FilebrowserValidator
     */
    protected $validator;

    /**
     * Filebrowser object holds specific data
     *
     * @var \Drupal\filebrowser\Filebrowser
     */
    protected $filebrowser;

    /**
     * @var array
     * Array of fid of files to delete
     */
    protected $itemsToAdd;

    /**
     * ConfirmForm constructor.
     */
    public function __construct() {
        $this->validator = \Drupal::service('filebrowser.validator');
        $this->common = \Drupal::service('filebrowser.common');
        $this->itemsToAdd = null;

    }

    public function getFormId() {
        return 'flysystem_objective_file_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state, $nid = null, $query_fid = 0, $fids_str =  null, $ajax = null) {

        $this->node = Node::load($nid);
        $this->queryFid = $query_fid;
        $this->filebrowser = $this->node->filebrowser;
        $fids = explode(',', $fids_str);
        // $this->error = false;

        // This flag indicates that a folder has been selected for deletion.
        $file_selected = false;
        $files = $this->common->nodeContentLoadMultiple($fids);
        foreach ($files as $fid => $file) {
            // Additional data.
            $file['type'] = unserialize($file['file_data'])->type;
            $file['full_path'] = $this->validator->encodingToFs($this->filebrowser->encoding, $this->validator->getNodeRoot($this->filebrowser->folderPath . $file['path']));
            $file['display_name'] = $this->validator->safeBaseName($file['full_path']);

            // Store item data.
            $this->itemsToAdd[$fid] = $file;
        }

        // Compose the list of files being deleted.
        $list = '<ul>';
        foreach ($this->itemsToAdd as $item) {

            $list .= '<li>';
            if ($item['type'] == 'dir') {
                $file_selected = true;
                $list .= '<b>' . $item['display_name'] . '</b>';
            }
            else {
                $list .= $item['display_name'];
            }
            $list .= '</li>';
        }
        $list .= '</ul>';

        if ($ajax) {
            $form['#attributes'] = [
                'class' => [
                    'form-in-slide-down'
                ],
            ];
            // Add a close slide-down-form button
            $form['close_button'] = $this->common->closeButtonMarkup();
        }
        $form['items'] = [
            '#type' => 'item',
            '#title' => $this->t('Items being added'),
            '#markup' => $list
        ];

        // If at least a folder has been selected, add a confirmation checkbox.
        if ($file_selected) {
            $form['confirmation'] = [
                '#type' => 'checkbox',
                '#title' => $this->t('Confirm addition of selected <b>folders</b> and all of their content.'),
                '#default_value' => false,
            ];
        }
        else {
            // No confirmation needed, we'll add a "fake" field.
            $form['confirmation'] = [
                '#type' => 'value',
                '#value' => TRUE,
            ];
        }
        $form = parent::buildForm($form, $form_state);
        $form['actions']['cancel']['#attributes']['class'][] = 'button btn btn-default';
        if($ajax) {
            $form['actions']['submit']['#attributes']['class'][] = 'use-ajax-submit';
            $this->ajax = true;
        }
        return $form;
    }

    public function getQuestion() {
        return $this->t('Are you sure you want to add the following items?');
    }

    public function getCancelUrl() {
        return $this->node->urlInfo();
    }

    public function getDescription() {
        return $this->t('The file will be added to the View area.');
    }

    public function getConfirmText() {
        return $this->t('Add');
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {

        if ($this->error) {
            // Create an AjaxResponse.
            $response = new AjaxResponse();
            // Remove old events
            $response->addCommand(new RemoveCommand('#filebrowser-form-action-error'));
            $response->addCommand(new RemoveCommand('.form-in-slide-down'));
            // Insert event details after event.
            $response->addCommand(new AfterCommand('#form-action-actions-wrapper', $form));
            // $response->addCommand(new AfterCommand('#form-action-actions-wrapper', $html));
            $response->addCommand(new AlertCommand($this->t('You must confirm addition of selected folders.')));
            $form_state->setResponse($response);
        } else {
            foreach ($this->itemsToAdd as $item) {
                $data = unserialize($item['file_data']);
                      $file = \Drupal::entityTypeManager()->getStorage('file')->create([
                    'uri' => $item['full_path'],
                    'uid' => \Drupal::currentUser()->id(),
                ]);

                $file->setPermanent();
                $file->save();
                \Drupal::database()->insert('objective_files')
                    ->fields([
                        'fid',
                     ])
                    ->values(array(
                        $file->id(),
                    ))
                    ->execute();
                // Load existing node and attach file.
                $success = true;
                if ($success) {
                    // invalidate the cache for this node
                    Cache::invalidateTags(['filebrowser:node:' . $this->node->id()]);
                }
                else {
                    drupal_set_message($this->t('Unable to delete @file', ['@file' => $data->uri]), 'warning');
                }
            }



            /**
             * {@inheritdoc}
             */

             //   if (!empty($form_state->getTriggeringElement()['#eb_widget_main_submit'])) {
             //       $files = $this->prepareEntities($form, $form_state);
//


               //     foreach ($files as $file) {
                   //     $file->save();
                //    }
                //    $this->selectEntities($files, $form_state);
             //   }

            drupal_set_message($this->t('Added files'), 'message');
            $route = $this->common->redirectRoute($this->queryFid, $this->node->id());

            if($this->ajax) {
                \Drupal::logger('ajax')->warning('reached in ajax');
                $response_url = Url::fromRoute($route['name'], $route['node'], $route['query']);
                $response = new AjaxResponse();
                $response->addCommand(new RedirectCommand($response_url->toString()));
                $form_state->setResponse($response);
            } else {
                \Drupal::logger('notajax')->warning('reached in not ajax');
                $form_state->setRedirect($route['name'], $route['node'], $route['query']);
            }
        }
    }

    public function validateForm(array &$form, FormStateInterface $form_state) {
        // Check if the confirmation checkbox has been checked.
        if (empty($form_state->getValue('confirmation'))) {
            // commented out original code below
            // https://www.drupal.org/project/filebrowser/issues/2955654
            //  $this->common->debugToConsole('validate');
            //  $form_state->setErrorByName('confirmation', $this->t('You must confirm deletion of selected folders.'));
            $this->error = true;
        }
        // Check if the confirmation checkbox has been checked.
        parent::validateForm($form, $form_state);
    }
    /**
     * {@inheritdoc}
     */
    protected function prepareEntities(array $form, FormStateInterface $form_state) {
        /** @var \Drupal\file\FileInterface $file */
        $file = \Drupal::entityTypeManager()->getStorage('file')->create([
            'uri' => $form_state->getValue('url'),
            'uid' => \Drupal::currentUser()->id(),
        ]);

        $file->setPermanent();

        return [$file];
    }
    /**
     * Dispatches event that informs all subscribers about new selected entities.
     *
     * @param array $entities
     *   Array of entities.
     */
    protected function selectEntities(array $entities, FormStateInterface $form_state) {
        $selected_entities = &$form_state->get(['entity_browser', 'selected_entities']);

        $selected_entities = array_merge($selected_entities, $entities);
        \Drupal::logger('entities')->warning('<pre>'.print_r($entities,true).' </pre>');
        $dispatcher = \Drupal::service('event_dispatcher');
        $dispatcher->dispatch(
            Events::SELECTED,
            new EntitySelectionEvent(
                $this->configuration['entity_browser_id'],
                $form_state->get(['entity_browser', 'instance_uuid']),
                $entities
            ));
    }

}