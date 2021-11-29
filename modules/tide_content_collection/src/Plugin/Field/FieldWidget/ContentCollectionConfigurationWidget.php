<?php

namespace Drupal\tide_content_collection\Plugin\Field\FieldWidget;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextareaWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tide_content_collection\SearchApiIndexHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implementation of the content collection configuration widget.
 *
 * @FieldWidget(
 *   id = "content_collection_configuration",
 *   label = @Translation("Content Collection Configuration"),
 *   field_types = {
 *     "content_collection_configuration"
 *   }
 * )
 */
class ContentCollectionConfigurationWidget extends StringTextareaWidget implements ContainerFactoryPluginInterface {

  /**
   * The Search API Index helper.
   *
   * @var \Drupal\tide_content_collection\SearchApiIndexHelper
   */
  protected $indexHelper;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The search API index.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, ModuleHandlerInterface $module_handler, SearchApiIndexHelper $index_helper) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->moduleHandler = $module_handler;
    $this->indexHelper = $index_helper;
    $this->getIndex();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('module_handler'),
      $container->get('tide_content_collection.search_api.index_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'tabs' => [
        'content' => [
          'content_types_wrapper' => [
            'enable_content_types' => '1',
            'contentTypes' => [],
          ],
          'field_topic_wrapper' => [
            'enable_field_topic' => '1',
            'show_field_topic_operator' => 0,
          ],
          'field_tags_wrapper' => [
            'enable_field_tags' => '1',
            'show_field_tags_operator' => 0,
          ],
        ],
        'enable_call_to_action' => 0,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() : array {
    $summary = [];
    $settings = $this->getSettings();
    $summary[] = $this->t('Call To Action: @status', [
      '@status' => $settings['tabs']['content']['enable_call_to_action'] ? $this->t('Enabled') : $this->t('Disabled'),
    ]);
    unset($settings['tabs']['content']['enable_call_to_action']);
    if (isset($settings['tabs']['content']['content_types_wrapper'])) {
      $summary[] = $this->t('Content types: @status', [
        '@status' => $settings['tabs']['content']['content_types_wrapper']['enable_content_types'] ? $this->t('Enabled') : $this->t('Disabled'),
      ]);
      if ($settings['tabs']['content']['content_types_wrapper']['enable_content_types']) {
        $summary[] = $this->t('Allowed content types: @values', [
          '@values' => $settings['tabs']['content']['content_types_wrapper']['contentTypes'] ? ucwords(str_replace('_', ' ', implode(',', array_map('ucwords', array_values(array_filter($settings['tabs']['content']['content_types_wrapper']['contentTypes'])))))) : $this->t('All'),
        ]);
      }
    }
    unset($settings['tabs']['content']['content_types_wrapper']);
    if (!empty($settings['tabs']['content'])) {
      foreach ($settings['tabs']['content'] as $key => $value) {
        $field_id = str_replace('_wrapper', '', $key);
        $summary[] = $this->t('@field: @status', [
          '@field' => ucfirst(str_replace('_', ' ', $field_id)),
          '@status' => $value['enable_' . $field_id] ? $this->t('Enabled') : $this->t('Disabled'),
        ]);
        if ($value['enable_' . $field_id]) {
          $summary[] = $this->t('@field operator: @status', [
            '@field' => ucfirst(str_replace('_', ' ', $field_id)),
            '@status' => $value['show_' . $field_id . '_operator'] ? $this->t('Exposed') : $this->t('Hidden'),
          ]);
        }
        unset($settings['tabs']['content'][$key]);
      }
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) : array {
    $element = [];
    $element['#attached']['library'][] = 'field_group/formatter.horizontal_tabs';
    $settings = $this->getSettings();
    // Load and verify the index.
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->getIndex();

    $element['tabs'] = [
      '#type' => 'horizontal_tabs',
      '#tree' => TRUE,
      '#group_name' => 'tabs',
    ];
    $element['tabs']['content'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#title' => $this->t('Content'),
      '#group_name' => 'tabs_content',
    ];

    $element['tabs']['content']['enable_call_to_action'] = [
      '#type' => 'checkbox',
      '#default_value' => $settings['tabs']['content']['enable_call_to_action'] ?? [],
      '#title' => $this->t('Enable call to action'),
      '#default_value' => $settings['tabs']['content']['enable_call_to_action'] ?? FALSE,
      '#weight' => 1,
    ];

    if ($content_type_options = $this->indexHelper->getNodeTypes()) {
      $element['tabs']['content']['content_types_wrapper'] = [
        '#type' => 'details',
        '#title' => 'Content Types',
        '#open' => FALSE,
        '#collapsible' => TRUE,
        '#weight' => 2,
      ];
      $element['tabs']['content']['content_types_wrapper']['enable_content_types'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable content types'),
        '#default_value' => $settings['tabs']['content']['content_types_wrapper']['enable_content_types'] ?? FALSE,
      ];
      $element['tabs']['content']['content_types_wrapper']['contentTypes'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed content types'),
        '#description' => $this->t('When no content type is selected in the widget settings, the widget will show all available content types in the Select content type filter.'),
        '#options' => $content_type_options,
        '#default_value' => $settings['tabs']['content']['content_types_wrapper']['contentTypes'] ?? [],
        '#weight' => 1,
      ];
    }

    if ($this->indexHelper->isFieldTopicIndexed($index)) {
      $element['tabs']['content']['field_topic_wrapper'] = [
        '#type' => 'details',
        '#title' => 'Field Topic',
        '#open' => FALSE,
        '#collapsible' => TRUE,
        '#weight' => 2,
      ];
      $element['tabs']['content']['field_topic_wrapper']['enable_field_topic'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable field topic'),
        '#default_value' => $settings['tabs']['content']['field_topic_wrapper']['enable_field_topic'] ?? FALSE,
      ];
      $element['tabs']['content']['field_topic_wrapper']['show_field_topic_operator'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show field topic operator'),
        '#default_value' => $settings['tabs']['content']['field_topic_wrapper']['show_field_topic_operator'] ?? FALSE,
      ];
    }

    if ($this->indexHelper->isFieldTopicIndexed($index)) {
      $element['tabs']['content']['field_tags_wrapper'] = [
        '#type' => 'details',
        '#title' => 'Field Tags',
        '#open' => FALSE,
        '#collapsible' => TRUE,
        '#weight' => 2,
      ];
      $element['tabs']['content']['field_tags_wrapper']['enable_field_tags'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable field tags'),
        '#default_value' => $settings['tabs']['content']['field_tags_wrapper']['enable_field_tags'] ?? FALSE,
      ];
      $element['tabs']['content']['field_tags_wrapper']['show_field_tags_operator'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show field tags operator'),
        '#default_value' => $settings['tabs']['content']['field_tags_wrapper']['show_field_tags_operator'] ?? FALSE,
      ];
    }

    $entity_reference_fields = $this->getEntityReferenceFields();
    if (!empty($entity_reference_fields)) {
      foreach ($entity_reference_fields as $field_id => $field_label) {
        $element['tabs']['content'][$field_id . '_wrapper'] = [
          '#type' => 'details',
          '#title' => $field_label,
          '#open' => FALSE,
          '#collapsible' => TRUE,
          '#weight' => 2,
        ];
        $element['tabs']['content'][$field_id . '_wrapper']['enable_' . $field_id] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable @field_id', ['@field_id' => str_replace('_', ' ', $field_id)]),
          '#default_value' => $settings['tabs']['content'][$field_id . '_wrapper']['enable_' . $field_id] ?? FALSE,
        ];
        $element['tabs']['content'][$field_id . '_wrapper']['show_' . $field_id . '_operator'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Show @field_id operator', ['@field_id' => str_replace('_', ' ', $field_id)]),
          '#default_value' => $settings['tabs']['content'][$field_id . '_wrapper']['show_' . $field_id . '_operator'] ?? FALSE,
        ];
      }
    }

    $element['tabs']['layout'] = [
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#title' => $this->t('Layout'),
      '#group_name' => 'tabs_layout',
    ];

    return $element;
  }

  /**
   * Get search API index.
   *
   * @return \Drupal\search_api\IndexInterface|null|false
   *   The index, NULL upon failure, FALSE when no index is selected.
   */
  protected function getIndex() {
    if (!$this->index) {
      // Load and verify the index.
      /** @var \Drupal\search_api\IndexInterface $index */
      $index = NULL;
      $index_id = $this->fieldDefinition->getFieldStorageDefinition()
        ->getSetting('index');
      if ($index_id) {
        $index = $this->indexHelper->loadSearchApiIndex($index_id);
        if ($index && $this->indexHelper->isValidNodeIndex($index)) {
          $this->index = $index;
        }
      }
      else {
        return FALSE;
      }
    }

    return $this->index;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $settings = $this->getSettings();

    // Hide the YAML configuration field.
    $element['value']['#access'] = FALSE;

    // Load and verify the index.
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->getIndex();
    $index_error = '';
    if ($index === NULL) {
      $index_error = $this->t('Invalid Search API Index.');
    }
    elseif ($index === FALSE) {
      $index_error = $this->t('No Search API Index has been selected for this field.');
    }

    if (!$index) {
      $element['error'] = [
        '#type' => 'markup',
        '#markup' => $index_error,
        '#prefix' => '<div class="form-item--error-message">',
        '#suffix' => '</div>',
        '#allowed_tags' => ['div'],
      ];
      return $element;
    }

    $json = $element['value']['#default_value'];
    $json_object = [];
    if (!empty($json)) {
      $json_object = json_decode($json, TRUE);
      if ($json_object === NULL) {
        $json_object = [];
      }
    }

    $element['title'] = [
      '#title' => $this->t('Title'),
      '#type' => 'textfield',
      '#description' => 'Title displayed above results.',
      '#default_value' => $json_object['title'] ?? '',
      '#weight' => 1,
    ];

    $element['description'] = [
      '#title' => $this->t('Description'),
      '#type' => 'textarea',
      '#description' => 'Description displayed above the results',
      '#default_value' => $json_object['description'] ?? '',
      '#weight' => 2,
    ];

    if (!empty($settings['tabs']['content']['enable_call_to_action']) && $settings['tabs']['content']['enable_call_to_action']) {
      $element['callToAction'] = [
        '#type' => 'details',
        '#title' => $this->t('Call To Action'),
        '#description' => 'A link to another page.',
        '#open' => TRUE,
        '#weight' => 3,
      ];
      $element['callToAction']['text'] = [
        '#type'  => 'textfield',
        '#title'  => $this->t('Text'),
        '#default_value' => $json_object['callToAction']['text'] ?? '',
        '#description' => $this->t('Display text of the link.'),
      ];
      $element['callToAction']['url'] = [
        '#type' => 'url',
        '#title'  => $this->t('Url'),
        '#default_value' => $json_object['callToAction']['url'] ?? '',
      ];
    }

    $configuration = $items[$delta]->configuration ?? [];

    $element['#attached']['library'][] = 'field_group/formatter.horizontal_tabs';

    $element['tabs'] = [
      '#type' => 'horizontal_tabs',
      '#tree' => TRUE,
      '#weight' => 4,
      '#group_name' => 'tabs',
    ];

    $this->buildContentTab($items, $delta, $element, $form, $form_state, $configuration, $json_object);
    $this->buildLayoutTab($items, $delta, $element, $form, $form_state, $configuration, $json_object);

    return $element;
  }

  /**
   * Build Content Tab.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   The current delta.
   * @param array $element
   *   The element.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $configuration
   *   The YAML configuration of the listing.
   * @param array $json_object
   *   The json_object of the listing.
   */
  protected function buildContentTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL, array $json_object = NULL) {
    $settings = $this->getSettings();
    $element['tabs']['content'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#title' => $this->t('Content'),
      '#group_name' => 'tabs_content',
    ];

    if ($this->indexHelper->isNodeTypeIndexed($this->index) && !empty($settings['tabs']['content']['content_types_wrapper']['enable_content_types'])) {
      $content_types_options = $this->indexHelper->getNodeTypes();
      $allowed_content_types = array_filter($settings['tabs']['content']['content_types_wrapper']['contentTypes']);
      if (!empty($allowed_content_types)) {
        foreach ($content_types_options as $key => $value) {
          if (!isset($allowed_content_types[$key])) {
            unset($content_types_options[$key]);
          }
        }
      }
      $element['tabs']['content']['contentTypes'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select content types'),
        '#options' => $content_types_options,
        '#default_value' => $json_object['internal']['contentTypes'] ?? [],
        '#weight' => 1,
      ];
    }

    if ($this->indexHelper->isFieldTopicIndexed($this->index) && !empty($settings['tabs']['content']['field_topic_wrapper']['enable_field_topic'])) {
      $default_values = $json_object['internal']['contentFields']['field_topic']['values'] ?? [];
      $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, 'field_topic', $default_values);
      if ($field_filter) {
        $element['tabs']['content']['field_topic_wrapper'] = [
          '#type' => 'details',
          '#title' => 'Select topics',
          '#open' => FALSE,
          '#collapsible' => TRUE,
          '#group_name' => 'tabs_content_filters_field_topic_wrapper',
          '#weight' => 2,
        ];
        $element['tabs']['content']['field_topic_wrapper']['field_topic'] = $field_filter;
        if ($settings['tabs']['content']['field_topic_wrapper']['show_field_topic_operator']) {
          $element['tabs']['content']['field_topic_wrapper']['operator'] = $this->buildFilterOperatorSelect($json_object['internal']['contentFields']['field_topic']['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
        }
        if (isset($json_object['internal']['contentFields']['field_topic'])) {
          $element['tabs']['content']['field_topic_wrapper']['#open'] = TRUE;
        }
      }
    }

    if ($this->indexHelper->isFieldTagsIndexed($this->index)  && !empty($settings['tabs']['content']['field_tags_wrapper']['enable_field_tags'])) {
      $default_values = $json_object['internal']['contentFields']['field_tags']['values'] ?? [];
      $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, 'field_tags', $default_values);
      if ($field_filter) {
        $element['tabs']['content']['field_tags_wrapper'] = [
          '#type' => 'details',
          '#title' => 'Select tags',
          '#open' => FALSE,
          '#collapsible' => TRUE,
          '#group_name' => 'tabs_content_filters_field_tags_wrapper',
          '#weight' => 3,
        ];
        $element['tabs']['content']['field_tags_wrapper']['field_tags'] = $field_filter;
        if ($settings['tabs']['content']['field_tags_wrapper']['show_field_tags_operator']) {
          $element['tabs']['content']['field_tags_wrapper']['operator'] = $this->buildFilterOperatorSelect($json_object['internal']['contentFields']['field_tags']['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
        }
        if (isset($json_object['internal']['contentFields']['field_tags'])) {
          $element['tabs']['content']['field_tags_wrapper']['#open'] = TRUE;
        }
      }
    }

    $element['tabs']['content']['show_advanced_filters'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show advanced filters.'),
      '#description' => $this->t('Show detailed filters to further limit the overall results.'),
      '#default_value' => FALSE,
      '#access' => FALSE,
      '#weight' => 4,
    ];

    $element['tabs']['content']['advanced_filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced filters'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#access' => FALSE,
      '#group_name' => 'tabs_content_advanced_filters',
      '#weight' => 5,
      '#states' => [
        'visible' => [
          ':input[name="' . $this->getFormStatesElementName('tabs|content|show_advanced_filters', $items, $delta, $element) . '"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Generate all entity reference filters.
    $entity_reference_fields = $this->getEntityReferenceFields($items, $delta);

    if (!empty($entity_reference_fields)) {
      foreach ($entity_reference_fields as $field_id => $field_label) {
        if (!empty($settings['tabs']['content'][$field_id . '_wrapper']['enable_' . $field_id])) {
          $default_values = $json_object['internal']['contentFields'][$field_id]['values'] ?? [];
          $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, $field_id, $default_values);
          if ($field_filter) {
            $element['tabs']['content']['show_advanced_filters']['#access'] = TRUE;
            $element['tabs']['content']['advanced_filters']['#access'] = TRUE;
            $element['tabs']['content']['advanced_filters'][$field_id . '_wrapper'] = [
              '#type' => 'details',
              '#title' => $field_label,
              '#open' => FALSE,
              '#collapsible' => TRUE,
              '#group_name' => 'tabs_content_advanced_filters_' . $field_id . '_wrapper',
            ];
            $element['tabs']['content']['advanced_filters'][$field_id . '_wrapper'][$field_id] = $field_filter;
            if ($settings['tabs']['content'][$field_id . '_wrapper']['show_' . $field_id . '_operator']) {
              $element['tabs']['content']['advanced_filters'][$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($json_object['internal']['contentFields'][$field_id]['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
            }
            if (isset($json_object['internal']['contentFields'][$field_id]['values'])) {
              $element['tabs']['content']['advanced_filters'][$field_id . '_wrapper']['#open'] = TRUE;
              $element['tabs']['content']['show_advanced_filters']['#default_value'] = TRUE;
            }
          }
        }
      }
    }

    // Build internal extra filters.
    $internal_extra_filters = $this->moduleHandler->invokeAll('tide_content_collection_internal_extra_filters_build', [
      $this->index,
      clone $items,
      $delta,
      $json_object['internal']['contentFields'] ?? [],
    ]);
    $context = [
      'index' => clone $items,
      'delta' => $delta,
      'filters' => $json_object['internal']['contentFields'] ?? [],
    ];
    $this->moduleHandler->alter('tide_content_collection_internal_extra_filters_build', $internal_extra_filters, $this->index, $context);
    if (!empty($internal_extra_filters) && is_array($internal_extra_filters)) {
      foreach ($internal_extra_filters as $field_id => $field_filter) {
        // Skip entity reference fields in internal extra filters.
        if (isset($entity_reference_fields[$field_id])) {
          continue;
        }
        $index_field = $this->index->getField($field_id);
        if ($index_field) {
          $element['tabs']['content']['show_advanced_filters']['#access'] = TRUE;
          $element['tabs']['content']['advanced_filters']['#access'] = TRUE;
          $element['tabs']['content']['advanced_filters'][$field_id . '_wrapper'] = [
            '#type' => 'details',
            '#title' => $index_field->getLabel(),
            '#open' => FALSE,
            '#collapsible' => TRUE,
            '#group_name' => 'filters' . $field_id . '_wrapper',
          ];
          $element['tabs']['content']['advanced_filters'][$field_id . '_wrapper'][$field_id] = $field_filter;
          if (empty($field_filter['#disable_filter_operator'])) {
            $element['tabs']['content']['advanced_filters'][$field_id . '_wrapper']['operator'] = $this->buildFilterOperatorSelect($json_object['internal']['contentFields'][$field_id]['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
          }
          unset($field_filter['#disable_filter_operator']);
          if (isset($json_object['internal']['contentFields'][$field_id]['values'])) {
            $element['tabs']['content']['advanced_filters'][$field_id . '_wrapper']['#open'] = TRUE;
            $element['tabs']['content']['show_advanced_filters']['#default_value'] = TRUE;
          }
        }
      }
    }

    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    if (!empty($date_fields)) {
      $element['tabs']['content']['show_dateFilter'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show date filter.'),
        '#default_value' => FALSE,
        '#weight' => 6,
      ];

      $element['tabs']['content']['dateFilter'] = [
        '#type' => 'details',
        '#title' => $this->t('Date filter'),
        '#open' => TRUE,
        '#collapsible' => TRUE,
        '#group_name' => 'tabs_content_dateFilter',
        '#weight' => 7,
        '#states' => [
          'visible' => [
            ':input[name="' . $this->getFormStatesElementName('tabs|content|show_dateFilter', $items, $delta, $element) . '"]' => ['checked' => TRUE],
          ],
        ],
      ];
      if (!empty($json_object['internal']['dateFilter'])) {
        $element['tabs']['content']['dateFilter']['#open'] = TRUE;
        $element['tabs']['content']['show_dateFilter']['#default_value'] = TRUE;
      }

      $element['tabs']['content']['dateFilter']['criteria'] = [
        '#type' => 'select',
        '#title' => $this->t('Criteria'),
        '#default_value' => $json_object['internal']['dateFilter']['criteria'] ?? 'today',
        '#options' => [
          'today' => $this->t('Today'),
          'this_week' => $this->t('This Week'),
          'this_month' => $this->t('This Month'),
          'this_year' => $this->t('This Year'),
          'today_and_future' => $this->t('Today And Future'),
          'past' => $this->t('Past'),
          'range' => $this->t('Range'),
        ],
      ];
      $default_filter_today_start_date = $json_object['internal']['dateFilter']['startDateField'] ?? '';
      if (!isset($date_fields[$default_filter_today_start_date])) {
        $default_filter_today_start_date = '';
      }
      $default_filter_today_end_date = $json_object['internal']['dateFilter']['endDateField'] ?? '';
      if (!isset($date_fields[$default_filter_today_end_date])) {
        $default_filter_today_end_date = '';
      }
      $element['tabs']['content']['dateFilter']['startDateField'] = [
        '#type' => 'select',
        '#title' => $this->t('Start date'),
        '#default_value' => $default_filter_today_start_date,
        '#options' => ['' => $this->t('- No mapping -')] + $date_fields,
      ];
      $element['tabs']['content']['dateFilter']['endDateField'] = [
        '#type' => 'select',
        '#title' => $this->t('End date'),
        '#default_value' => $default_filter_today_end_date,
        '#options' => ['' => $this->t('- No mapping -')] + $date_fields,
      ];
      $element['tabs']['content']['dateFilter']['dateRange'] = [
        '#type' => 'details',
        '#title' => $this->t('Date range'),
        '#open' => TRUE,
        '#collapsible' => TRUE,
        '#group_name' => 'tabs_content_dateRange',
        '#weight' => 7,
        '#states' => [
          'visible' => [
            ':input[name="' . $this->getFormStatesElementName('tabs|content|dateFilter|criteria', $items, $delta, $element) . '"]' => ['value' => 'range'],
          ],
        ],
      ];
      $element['tabs']['content']['dateFilter']['dateRange']['dateRangeStart'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Date range start'),
        '#default_value' => $json_object['internal']['dateFilter']['dateRangeStart'] ?? '',
      ];
      $element['tabs']['content']['dateFilter']['dateRange']['dateRangeEnd'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Date range end'),
        '#default_value' => $json_object['internal']['dateFilter']['dateRangeEnd'] ?? '',
      ];
    }

  }

  /**
   * Get all entity reference fields.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   The current delta.
   *
   * @return array
   *   The reference fields.
   */
  protected function getEntityReferenceFields(FieldItemListInterface $items = NULL, $delta = NULL) {
    $entity_reference_fields = $this->indexHelper->getIndexEntityReferenceFields($this->index, ['nid']);
    // Allow other modules to remove entity reference filters.
    $excludes = $this->moduleHandler->invokeAll('tide_content_collection_entity_reference_fields_exclude', [
      $this->index,
      $entity_reference_fields,
      !empty($items) ? clone $items : NULL,
      $delta,
    ]);
    // Exclude the below fields as they are loaded manually.
    $excludes[] = 'field_topic';
    $excludes[] = 'field_tags';
    if (!empty($excludes) && is_array($excludes)) {
      $entity_reference_fields = $this->indexHelper::excludeArrayKey($entity_reference_fields, $excludes);
    }
    return $entity_reference_fields;
  }

  /**
   * Build Layout Tab.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   The current delta.
   * @param array $element
   *   The element.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $configuration
   *   The YAML configuration of the listing.
   * @param array $json_object
   *   The json_object of the listing.
   */
  protected function buildLayoutTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL, array $json_object = NULL) {
    $settings = $this->getSettings();
    $element['tabs']['layout'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#title' => $this->t('Layout'),
      '#group_name' => 'layout',
    ];

    $element['tabs']['layout']['display']['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select layout'),
      '#default_value' => $json_object['interface']['display']['type'] ?? 'grid',
      '#options' => [
        'grid' => $this->t('Grid view'),
        'list' => $this->t('List view'),
      ],
    ];
  }

  /**
   * Build a filter operator select element.
   *
   * @param string $default_value
   *   The default operator.
   * @param string $description
   *   The description of the operator.
   *
   * @return string[]
   *   The form element.
   */
  protected function buildFilterOperatorSelect($default_value = 'AND', $description = NULL) {
    return [
      '#type' => 'select',
      '#title' => $this->t('Filter operator'),
      '#description' => $description,
      '#default_value' => $default_value ?? 'AND',
      '#options' => [
        'AND' => $this->t('AND'),
        'OR' => $this->t('OR'),
      ],
    ];
  }

  /**
   * Get the element name for Form States API.
   *
   * @param string $element_name
   *   The name of the element.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   Delta.
   * @param array $element
   *   The element.
   *
   * @return string
   *   The final element name.
   */
  protected function getFormStatesElementName($element_name, FieldItemListInterface $items, $delta, array $element) {
    $name = '';
    foreach ($element['#field_parents'] as $index => $parent) {
      $name .= $index ? ('[' . $parent . ']') : $parent;
    }
    $name .= '[' . $items->getName() . ']';
    $name .= '[' . $delta . ']';
    foreach (explode('|', $element_name) as $path) {
      $name .= '[' . $path . ']';
    }
    return $name;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $values = parent::massageFormValues($values, $form, $form_state);
    foreach ($values as $delta => &$value) {
      $config = [];
      $config['title'] = $value['title'] ?? '';
      $config['description'] = $value['description'] ?? '';
      $config['callToAction']['text'] = $value['callToAction']['text'] ?? '';
      $config['callToAction']['url'] = $value['callToAction']['url'] ?? '';
      $config['internal']['contentTypes'] = $value['tabs']['content']['contentTypes'] ? array_values(array_filter($value['tabs']['content']['contentTypes'])) : [];
      if (isset($value['tabs']['content']['field_topic_wrapper']['field_topic'])) {
        foreach ($value['tabs']['content']['field_topic_wrapper']['field_topic'] as $index => $reference) {
          if (!empty($reference['target_id'])) {
            $config['internal']['contentFields']['field_topic']['values'][] = (int) $reference['target_id'];
          }
        }
        $config['internal']['contentFields']['field_topic']['operator'] = $value['tabs']['content']['field_topic_wrapper']['operator'] ?? NULL;
      }
      if (isset($value['tabs']['content']['field_tags_wrapper']['field_tags'])) {
        foreach ($value['tabs']['content']['field_tags_wrapper']['field_tags'] as $index => $reference) {
          if (!empty($reference['target_id'])) {
            $config['internal']['contentFields']['field_tags']['values'][] = (int) $reference['target_id'];
          }
        }
        $config['internal']['contentFields']['field_tags']['operator'] = $value['tabs']['content']['field_tags_wrapper']['operator'] ?? NULL;
      }

      $entity_reference_fields = $this->getEntityReferenceFields();
      foreach ($value['tabs']['content']['advanced_filters'] as $wrapper_id => $wrapper) {
        $field_id = str_replace('_wrapper', '', $wrapper_id);
        if (isset($wrapper[$field_id])) {
          // Entity reference fields.
          if (isset($entity_reference_fields[$field_id])) {
            foreach ($wrapper[$field_id] as $index => $reference) {
              if (!empty($reference['target_id'])) {
                $config['internal']['contentFields'][$field_id]['values'][] = (int) $reference['target_id'];
              }
            }
          }
          // Internal Extra fields.
          else {
            $config['internal']['contentFields'][$field_id]['values'] = is_array($wrapper[$field_id]) ? array_values(array_filter($wrapper[$field_id])) : [$wrapper[$field_id]];
            $config['internal']['contentFields'][$field_id]['values'] = array_filter($config['internal']['contentFields'][$field_id]['values']);
          }

          if (!empty($wrapper['operator'])) {
            $config['internal']['contentFields'][$field_id]['operator'] = $wrapper['operator'];
          }

          if (empty($config['internal']['contentFields'][$field_id]['values'])) {
            unset($config['internal']['contentFields'][$field_id]);
          }
        }
      }

      // Date Filters.
      if (!empty($value['tabs']['content']['dateFilter']['criteria'])) {
        $config['internal']['dateFilter']['criteria'] = $value['tabs']['content']['dateFilter']['criteria'] ?? '';
        if ($value['tabs']['content']['dateFilter']['criteria'] == 'range') {
          if (!empty($value['tabs']['content']['dateFilter']['dateRange']['dateRangeStart'])) {
            $config['internal']['dateFilter']['dateRangeStart'] = $value['tabs']['content']['dateFilter']['dateRange']['dateRangeStart'] ?? '';
          }
          if (!empty($value['tabs']['content']['dateFilter']['dateRange']['dateRangeEnd'])) {
            $config['internal']['dateFilter']['dateRangeEnd'] = $value['tabs']['content']['dateFilter']['dateRange']['dateRangeEnd'] ?? '';
          }
        }
      }

      if (!empty($value['tabs']['content']['dateFilter']['startDateField'])) {
        $config['internal']['dateFilter']['startDateField'] = $value['tabs']['content']['dateFilter']['startDateField'] ?? '';
      }

      if (!empty($value['tabs']['content']['dateFilter']['endDateField'])) {
        $config['internal']['dateFilter']['endDateField'] = $value['tabs']['content']['dateFilter']['endDateField'] ?? '';
      }

      // Display Layout.
      $config['interface']['display']['type'] = $value['tabs']['layout']['display']['type'] ?? 'grid';

      $value['value'] = json_encode($config);
    }

    return $values;
  }

}
