<?php

namespace Drupal\tide_content_collection\Plugin\Field\FieldWidget;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextareaWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tide_content_collection\SearchApiIndexHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\link\LinkItemInterface;

/**
 * Implementation of the content collection configuration widget.
 *
 * @FieldWidget(
 *   id = "content_collection_configuration_ui",
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
   * @var \Drupal\tide_content_collection\SearchApiIndexHelperInterface
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
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, ModuleHandlerInterface $module_handler, SearchApiIndexHelperInterface $index_helper, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->moduleHandler = $module_handler;
    $this->indexHelper = $index_helper;
    $this->getIndex();
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('tide_content_collection.search_api.index_helper'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'content' => [
        'internal' => [
          'contentTypes' => [
            'enabled' => TRUE,
            'allowed_values' => [],
            'default_values' => [],
          ],
          'field_topic' => [
            'enabled' => TRUE,
            'show_filter_operator' => FALSE,
            'default_values' => [],
          ],
          'field_tags' => [
            'enabled' => TRUE,
            'show_filter_operator' => FALSE,
            'default_values' => [],
          ],
        ],
        'enable_call_to_action' => FALSE,
      ],
      'filters' => [
        'enable_keyword_selection' => FALSE,
        'allowed_advanced_filters' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() : array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) : array {
    $element = [];
    $element['#attached']['library'][] = 'field_group/formatter.horizontal_tabs';
    $settings = $this->getSettings();
    $field_name = $this->fieldDefinition->getName();
    // Load and verify the index.
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $this->getIndex();

    $element['settings'] = [
      '#type' => 'horizontal_tabs',
      '#tree' => TRUE,
      '#group_name' => 'settings',
    ];
    $element['settings']['content'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#title' => $this->t('Content'),
      '#group_name' => 'tabs_content',
    ];

    $element['settings']['content']['enable_call_to_action'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Call to Action'),
      '#default_value' => $settings['content']['enable_call_to_action'] ?? FALSE,
      '#weight' => -1,
    ];

    $content_type_options = $this->indexHelper->getNodeTypes();
    if (!empty($content_type_options)) {
      $element['settings']['content']['internal']['contentTypes'] = [
        '#type' => 'details',
        '#title' => $this->t('Content Types'),
        '#open' => FALSE,
        '#collapsible' => TRUE,
        '#weight' => 2,
      ];
      $element['settings']['content']['internal']['contentTypes']['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable content types'),
        '#default_value' => $settings['content']['internal']['contentTypes']['enabled'] ?? FALSE,
      ];
      $element['settings']['content']['internal']['contentTypes']['allowed_values'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed content types'),
        '#description' => $this->t('When no content type is selected in the widget settings, the widget will show all available content types in the Select content type filter.'),
        '#options' => $content_type_options,
        '#default_value' => $settings['content']['internal']['contentTypes']['allowed_values'] ?? [],
        '#weight' => 1,
      ];
      $element['settings']['content']['internal']['contentTypes']['default_values'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Default content types'),
        '#description' => $this->t('When no content type is selected in the widget settings, the widget will show all available content types in the Select content type filter.'),
        '#options' => $content_type_options,
        '#default_value' => $settings['content']['internal']['contentTypes']['default_values'] ?? [],
        '#weight' => 1,
        '#states' => [
          'visible' => [
            ':input[name="fields[' . $field_name . '][settings_edit_form][settings][settings][content][internal][contentTypes][enabled]"]' => ['checked' => FALSE],
          ],
        ],
      ];
    }

    if ($this->indexHelper->isFieldTopicIndexed($index)) {
      $element['settings']['content']['internal']['field_topic'] = [
        '#type' => 'details',
        '#title' => $this->t('Topic'),
        '#open' => FALSE,
        '#collapsible' => TRUE,
        '#weight' => 2,
      ];
      $element['settings']['content']['internal']['field_topic']['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Topic filter'),
        '#default_value' => $settings['content']['internal']['field_topic']['enabled'] ?? FALSE,
      ];
      $element['settings']['content']['internal']['field_topic']['show_filter_operator'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show filter operator'),
        '#default_value' => $settings['content']['internal']['field_topic']['show_filter_operator'] ?? FALSE,
      ];
      $default_values = $settings['content']['internal']['field_topic']['default_values'] ?? [];
      $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, 'field_topic', $default_values);
      if ($field_filter) {
        $element['settings']['content']['internal']['field_topic']['default_values'] = $field_filter;
        $element['settings']['content']['internal']['field_topic']['default_values']['#title'] = $this->t('Default values for topics');
        $element['settings']['content']['internal']['field_topic']['default_values']['#states'] = [
          'visible' => [
            ':input[name="fields[' . $field_name . '][settings_edit_form][settings][settings][content][internal][field_topic][enabled]"]' => ['checked' => FALSE],
          ],
        ];
      }
    }

    if ($this->indexHelper->isFieldTagsIndexed($index)) {
      $element['settings']['content']['internal']['field_tags'] = [
        '#type' => 'details',
        '#title' => $this->t('Tags'),
        '#open' => FALSE,
        '#collapsible' => TRUE,
        '#weight' => 2,
      ];
      $element['settings']['content']['internal']['field_tags']['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Tags filter'),
        '#default_value' => $settings['content']['internal']['field_tags']['enabled'] ?? FALSE,
      ];
      $element['settings']['content']['internal']['field_tags']['show_filter_operator'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show filter operator'),
        '#default_value' => $settings['content']['internal']['field_tags']['show_filter_operator'] ?? FALSE,
      ];
      $default_values = $settings['content']['internal']['field_tags']['default_values'] ?? [];
      $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, 'field_tags', $default_values);
      if ($field_filter) {
        $element['settings']['content']['internal']['field_tags']['default_values'] = $field_filter;
        $element['settings']['content']['internal']['field_tags']['default_values']['#title'] = $this->t('Default values for tags');
        $element['settings']['content']['internal']['field_tags']['default_values']['#states'] = [
          'visible' => [
            ':input[name="fields[' . $field_name . '][settings_edit_form][settings][settings][content][internal][field_tags][enabled]"]' => ['checked' => FALSE],
          ],
        ];
      }
    }

    $entity_reference_fields = $this->getEntityReferenceFields();
    if (!empty($entity_reference_fields)) {
      foreach ($entity_reference_fields as $field_id => $field_label) {
        $element['settings']['content']['internal'][$field_id] = [
          '#type' => 'details',
          '#title' => $field_label,
          '#open' => FALSE,
          '#collapsible' => TRUE,
          '#weight' => 2,
        ];
        $element['settings']['content']['internal'][$field_id]['enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable @field_id filter', ['@field_id' => $field_id]),
          '#default_value' => $settings['content']['internal'][$field_id]['enabled'] ?? FALSE,
        ];
        $element['settings']['content']['internal'][$field_id]['show_filter_operator'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Show filter operator'),
          '#default_value' => $settings['content']['internal'][$field_id]['show_filter_operator'] ?? FALSE,
        ];
        $default_values = $default_values = $settings['content']['internal'][$field_id]['default_values'] ?? [];
        $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, $field_id, $default_values);
        if ($field_filter) {
          $element['settings']['content']['internal'][$field_id]['default_values'] = $field_filter;
          $element['settings']['content']['internal'][$field_id]['default_values']['#title'] = $this->t('Default values for @field_id', ['@field_id' => $field_id]);
          $element['settings']['content']['internal'][$field_id]['default_values']['#states'] = [
            'visible' => [
              ':input[name="fields[' . $field_name . '][settings_edit_form][settings][settings][content][internal][' . $field_id . '][enabled]"]' => ['checked' => FALSE],
            ],
          ];
        }
      }
    }

    $element['settings']['filters'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#title' => $this->t('Filters'),
      '#group_name' => 'tabs_filters',
    ];
    $element['settings']['filters']['enable_keyword_selection'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow selecting fields for keyword search'),
      '#default_value' => $settings['filters']['enable_keyword_selection'] ?? FALSE,
      '#weight' => 1,
    ];
    $advanced_filters_options = $this->getEntityReferenceFields(NULL, NULL, []);
    $element['settings']['filters']['allowed_advanced_filters'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed advanced filters'),
      '#options' => $advanced_filters_options,
      '#default_value' => $settings['filters']['allowed_advanced_filters'] ?? [],
      '#weight' => 2,
    ];

    $element['settings']['#element_validate'][] = [$this, 'validateSettings'];

    return $element;
  }

  /**
   * Handler #element_validate for the "tabs" form elements in settingsForm().
   *
   * Used to set the settings value in a clean structure.
   *
   * @param array $form
   *   The form where the settings form is being included in.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateSettings(array $form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $base_key = [
      'fields',
      $field_name,
      'settings_edit_form',
      'settings',
    ];
    $input = $form_state->getValue(array_merge($base_key, [
      'settings',
    ]));
    $form_state->unsetValue($base_key);
    $entity_reference_fields = $this->getEntityReferenceFields();
    if (isset($input['content']['enable_call_to_action'])) {
      $value = $input['content']['enable_call_to_action'] ? TRUE : FALSE;
      $form_state->setValue(array_merge($base_key, [
        'content',
        'enable_call_to_action',
      ]), $value);
    }
    if (isset($input['content']['internal']['contentTypes']['enabled'])) {
      $value = $input['content']['internal']['contentTypes']['enabled'] ? TRUE : FALSE;
      $form_state->setValue(array_merge($base_key, [
        'content',
        'internal',
        'contentTypes',
        'enabled',
      ]), $value);
    }
    $content_types_key = ['content', 'internal', 'contentTypes'];
    if (isset($input['content']['internal']['contentTypes']['allowed_values'])) {
      $value = $input['content']['internal']['contentTypes']['allowed_values'] ? array_values(array_filter($input['content']['internal']['contentTypes']['allowed_values'])) : [];
      $form_state->setValue(array_merge($base_key, $content_types_key, ['allowed_values']), $value);
    }
    if (isset($input['content']['internal']['contentTypes']['default_values'])) {
      $value = $input['content']['internal']['contentTypes']['default_values'] ? array_values(array_filter($input['content']['internal']['contentTypes']['default_values'])) : [];
      $form_state->setValue(array_merge($base_key, $content_types_key, ['default_values']), $value);
    }
    $field_topic_key = ['content', 'internal', 'field_topic'];
    if (isset($input['content']['internal']['field_topic']['enabled'])) {
      $value = $input['content']['internal']['field_topic']['enabled'] ? TRUE : FALSE;
      $form_state->setValue(array_merge($base_key, $field_topic_key, ['enabled']), $value);
    }
    if (isset($input['content']['internal']['field_topic']['show_filter_operator'])) {
      $value = $input['content']['internal']['field_topic']['show_filter_operator'] ? TRUE : FALSE;
      $form_state->setValue(array_merge($base_key, $field_topic_key, ['show_filter_operator']), $value);
    }
    if (isset($input['content']['internal']['field_topic']['default_values'])) {
      $value = $input['content']['internal']['field_topic']['default_values'] ? array_column(array_values(array_filter($input['content']['internal']['field_topic']['default_values'])), 'target_id') : [];
      $form_state->setValue(array_merge($base_key, $field_topic_key, ['default_values']), $value);
    }
    $field_tags_key = ['content', 'internal', 'field_tags'];
    if (isset($input['content']['internal']['field_tags']['enabled'])) {
      $value = $input['content']['internal']['field_tags']['enabled'] ? TRUE : FALSE;
      $form_state->setValue(array_merge($base_key, $field_tags_key, ['enabled']), $value);
    }
    if (isset($input['content']['internal']['field_tags']['show_filter_operator'])) {
      $value = $input['content']['internal']['field_tags']['show_filter_operator'] ? TRUE : FALSE;
      $form_state->setValue(array_merge($base_key, $field_tags_key, ['show_filter_operator']), $value);
    }
    if (isset($input['content']['internal']['field_tags']['default_values'])) {
      $value = $input['content']['internal']['field_tags']['default_values'] ? array_column(array_values(array_filter($input['content']['internal']['field_tags']['default_values'])), 'target_id') : [];
      $form_state->setValue(array_merge($base_key, $field_tags_key, ['default_values']), $value);
    }
    if (!empty($entity_reference_fields)) {
      foreach ($entity_reference_fields as $field_id => $field_label) {
        $field_id_key = ['content', 'internal', $field_id];
        if (isset($input['content']['internal'][$field_id]['enabled'])) {
          $value = $input['content']['internal'][$field_id]['enabled'] ? TRUE : FALSE;
          $form_state->setValue(array_merge($base_key, $field_id_key, ['enabled']), $value);
        }
        if (isset($input['content']['internal'][$field_id]['show_filter_operator'])) {
          $value = $input['content']['internal'][$field_id]['show_filter_operator'] ? TRUE : FALSE;
          $form_state->setValue(array_merge($base_key, $field_id_key, ['show_filter_operator']), $value);
        }
        if (isset($input['content']['internal'][$field_id]['default_values'])) {
          $value = $input['content']['internal'][$field_id]['default_values'] ? array_column(array_values(array_filter($input['content']['internal'][$field_id]['default_values'])), 'target_id') : [];
          $form_state->setValue(array_merge($base_key, $field_id_key, ['default_values']), $value);
        }
      }
    }
    if (isset($input['filters']['enable_keyword_selection'])) {
      $value = $input['filters']['enable_keyword_selection'] ? TRUE : FALSE;
      $form_state->setValue(array_merge($base_key, [
        'filters',
        'enable_keyword_selection',
      ]), $value);
    }
    if (isset($input['filters']['allowed_advanced_filters'])) {
      $value = $input['filters']['allowed_advanced_filters'] ? array_values(array_filter($input['filters']['allowed_advanced_filters'])) : [];
      $form_state->setValue(array_merge($base_key, [
        'filters',
        'allowed_advanced_filters',
      ]), $value);
    }
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
      '#description' => $this->t('Title displayed above results.'),
      '#default_value' => $json_object['title'] ?? '',
      '#weight' => 1,
    ];

    $element['description'] = [
      '#title' => $this->t('Description'),
      '#type' => 'textarea',
      '#description' => $this->t('Description displayed above the results'),
      '#default_value' => $json_object['description'] ?? '',
      '#weight' => 2,
    ];

    if (!empty($settings['content']['enable_call_to_action']) && $settings['content']['enable_call_to_action']) {
      $element['callToAction'] = [
        '#type' => 'details',
        '#title' => $this->t('Call To Action'),
        '#description' => $this->t('A link to another page.'),
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
        '#title'  => $this->t('URL'),
        '#type' => 'entity_autocomplete',
        '#link_type' => LinkItemInterface::LINK_GENERIC,
        '#target_type' => 'node',
        '#default_value' => (!empty($json_object['callToAction']['url']) && (\Drupal::currentUser()->hasPermission('link to any page'))) ? static::getUriAsDisplayableString($json_object['callToAction']['url']) : NULL,
        '#attributes' => [
          'data-autocomplete-first-character-blacklist' => '/#?',
        ],
        '#process_default_value' => FALSE,
        '#element_validate' => [[$this, 'validateUriElement']],
        '#description' => $this->t('Start typing the title of a piece of content to select it. You can also enter an internal path such as %add-node or an external URL such as %url. Enter %front to link to the front page. Enter %nolink to display link text only.', [
          '%front' => '<front>',
          '%add-node' => '/node/add',
          '%url' => 'http://example.com',
          '%nolink' => '<nolink>',
        ]),
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
    $this->buildFiltersTab($items, $delta, $element, $form, $form_state, $configuration, $json_object);
    $this->buildAdvancedTab($items, $delta, $element, $form, $form_state, $configuration, $json_object);

    return $element;
  }

  /**
   * Form element validation handler for the 'uri' element.
   *
   * Disallows saving inaccessible or untrusted URLs.
   */
  public static function validateUriElement($element, FormStateInterface $form_state, $form) {
    $uri = static::getUserEnteredStringAsUri($element['#value']);
    $form_state->setValueForElement($element, $uri);

    // If getUserEnteredStringAsUri() mapped the entered value to a 'internal:'
    // URI , ensure the raw value begins with '/', '?' or '#'.
    // @todo '<front>' is valid input for BC reasons, may be removed by
    //   https://www.drupal.org/node/2421941
    $valid_list = ['/', '?', '#'];
    if (parse_url($uri, PHP_URL_SCHEME) === 'internal' && !in_array($element['#value'][0], $valid_list, TRUE) && substr($element['#value'], 0, 7) !== '<front>') {
      $form_state->setError($element, t('Manually entered paths should start with one of the following characters: / ? #'));
      return;
    }
  }

  /**
   * Gets the URI without the 'internal:' or 'entity:' scheme.
   *
   * The following two forms of URIs are transformed:
   * - 'entity:' URIs: to entity autocomplete ("label (entity id)") strings;
   * - 'internal:' URIs: the scheme is stripped.
   *
   * This method is the inverse of ::getUserEnteredStringAsUri().
   *
   * @param string $uri
   *   The URI to get the displayable string for.
   *
   * @return string
   *   The human readable string display value.
   *
   * @see static::getUserEnteredStringAsUri()
   */
  protected static function getUriAsDisplayableString($uri) {
    $scheme = parse_url($uri, PHP_URL_SCHEME);

    // By default, the displayable string is the URI.
    $displayable_string = $uri;

    // A different displayable string may be chosen in case of the 'internal:'
    // or 'entity:' built-in schemes.
    if ($scheme === 'internal') {
      $uri_reference = explode(':', $uri, 2)[1];

      // @todo '<front>' is valid input for BC reasons, may be removed by
      //   https://www.drupal.org/node/2421941
      $path = parse_url($uri, PHP_URL_PATH);
      if ($path === '/') {
        $uri_reference = '<front>' . substr($uri_reference, 1);
      }

      $displayable_string = $uri_reference;
    }
    elseif ($scheme === 'entity') {
      list($entity_type, $entity_id) = explode('/', substr($uri, 7), 2);
      // Show the 'entity:' URI as the entity autocomplete would.
      // @todo Support entity types other than 'node'. Will be fixed in
      //   https://www.drupal.org/node/2423093.
      $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
      if ($entity_type === 'node' && !empty($entity)) {
        $displayable_string = EntityAutocomplete::getEntityLabels([$entity]);
      }
    }
    elseif ($scheme === 'route') {
      $displayable_string = ltrim($displayable_string, 'route:');
    }

    return $displayable_string;
  }

  /**
   * Gets the user-entered string as a URI.
   *
   * The following two forms of input are mapped to URIs:
   * - entity autocomplete ("label (entity id)") strings: to 'entity:' URIs;
   * - strings without a detectable scheme: to 'internal:' URIs.
   *
   * This method is the inverse of ::getUriAsDisplayableString().
   *
   * @param string $string
   *   The user-entered string.
   *
   * @return string
   *   The URI, if a non-empty $uri was passed.
   *
   * @see static::getUriAsDisplayableString()
   */
  protected static function getUserEnteredStringAsUri($string) {
    // By default, assume the entered string is an URI.
    $uri = trim($string);

    // Detect entity autocomplete string, map to 'entity:' URI.
    $entity_id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($string);
    if ($entity_id !== NULL) {
      // @todo Support entity types other than 'node'. Will be fixed in
      //   https://www.drupal.org/node/2423093.
      $uri = 'entity:node/' . $entity_id;
    }
    // Support linking to nothing.
    elseif (in_array($string, ['<nolink>', '<none>'], TRUE)) {
      $uri = 'route:' . $string;
    }
    // Detect a schemeless string, map to 'internal:' URI.
    elseif (!empty($string) && parse_url($string, PHP_URL_SCHEME) === NULL) {
      // @todo '<front>' is valid input for BC reasons, may be removed by
      //   https://www.drupal.org/node/2421941
      // - '<front>' -> '/'
      // - '<front>#foo' -> '/#foo'
      if (strpos($string, '<front>') === 0) {
        $string = '/' . substr($string, strlen('<front>'));
      }
      $uri = 'internal:' . $string;
    }

    return $uri;
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

    if ($this->indexHelper->isNodeTypeIndexed($this->index) && !empty($settings['content']['internal']['contentTypes']['enabled'])) {
      $content_types_options = $this->indexHelper->getNodeTypes();
      $allowed_content_types = $settings['content']['internal']['contentTypes']['allowed_values'];
      if (!empty($allowed_content_types)) {
        $content_types_options = array_intersect_key($content_types_options, array_flip($allowed_content_types));
        $element['tabs']['content']['contentTypes'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Select content types'),
          '#options' => $content_types_options,
          '#default_value' => $json_object['internal']['contentTypes'] ?? [],
          '#weight' => 1,
        ];
      }
    }

    if ($this->indexHelper->isFieldTopicIndexed($this->index) && !empty($settings['content']['internal']['field_topic']['enabled'])) {
      $default_values = $json_object['internal']['contentFields']['field_topic']['values'] ?? [];
      $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, 'field_topic', $default_values);
      if ($field_filter) {
        $element['tabs']['content']['field_topic_wrapper'] = [
          '#type' => 'details',
          '#title' => $this->t('Select topics'),
          '#open' => FALSE,
          '#collapsible' => TRUE,
          '#group_name' => 'tabs_content_filters_field_topic_wrapper',
          '#weight' => 2,
        ];
        $element['tabs']['content']['field_topic_wrapper']['field_topic'] = $field_filter;
        $element['tabs']['content']['field_topic_wrapper']['field_topic']['#title'] = $this->t('Select topics');
        if ($settings['content']['internal']['field_topic']['show_filter_operator']) {
          $element['tabs']['content']['field_topic_wrapper']['operator'] = $this->buildFilterOperatorSelect($json_object['internal']['contentFields']['field_topic']['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
        }
        if (isset($json_object['internal']['contentFields']['field_topic'])) {
          $element['tabs']['content']['field_topic_wrapper']['#open'] = TRUE;
        }
      }
    }

    if ($this->indexHelper->isFieldTagsIndexed($this->index)  && !empty($settings['content']['internal']['field_tags']['enabled'])) {
      $default_values = $json_object['internal']['contentFields']['field_tags']['values'] ?? [];
      $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, 'field_tags', $default_values);
      if ($field_filter) {
        $element['tabs']['content']['field_tags_wrapper'] = [
          '#type' => 'details',
          '#title' => $this->t('Select tags'),
          '#open' => FALSE,
          '#collapsible' => TRUE,
          '#group_name' => 'tabs_content_filters_field_tags_wrapper',
          '#weight' => 3,
        ];
        $element['tabs']['content']['field_tags_wrapper']['field_tags'] = $field_filter;
        $element['tabs']['content']['field_tags_wrapper']['field_tags']['#title'] = $this->t('Select tags');
        if ($settings['content']['internal']['field_tags']['show_filter_operator']) {
          $element['tabs']['content']['field_tags_wrapper']['operator'] = $this->buildFilterOperatorSelect($json_object['internal']['contentFields']['field_tags']['operator'] ?? 'OR', $this->t('This filter operator is used to combined all the selected values together.'));
        }
        if (isset($json_object['internal']['contentFields']['field_tags'])) {
          $element['tabs']['content']['field_tags_wrapper']['#open'] = TRUE;
        }
      }
    }

    $this->buildContentTabAdvancedFilters($items, $delta, $element, $form, $form_state, $configuration, $json_object, $settings);
    $this->buildContentTabDateFilters($items, $delta, $element, $form, $form_state, $configuration, $json_object, $settings);

  }

  /**
   * Build Content Tab Advanced Filters.
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
   * @param array $settings
   *   The settings of the listing.
   */
  protected function buildContentTabAdvancedFilters(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL, array $json_object = NULL, array $settings = []) {
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
    $entity_reference_fields = $this->getEntityReferenceFields();

    if (!empty($entity_reference_fields)) {
      foreach ($entity_reference_fields as $field_id => $field_label) {
        if (!empty($settings['content']['internal'][$field_id]['enabled'])) {
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
            if ($settings['content']['internal'][$field_id]['show_filter_operator']) {
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
    // Extra filters added via hook.
    $this->buildContentTabAdvancedExtraFilters($items, $delta, $element, $form, $form_state, $configuration, $json_object, $settings);
  }

  /**
   * Build Content Tab Advanced Extra Filters.
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
   * @param array $settings
   *   The settings of the listing.
   */
  protected function buildContentTabAdvancedExtraFilters(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL, array $json_object = NULL, array $settings = []) {
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
  }

  /**
   * Build Content Tab Date Filters.
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
   * @param array $settings
   *   The settings of the listing.
   */
  protected function buildContentTabDateFilters(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL, array $json_object = NULL, array $settings = []) {
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
      $default_date_range_start = '';
      $default_date_range_end = '';
      if (!empty($json_object['internal']['dateFilter']['dateRangeStart'])) {
        $default_date_range_start = DrupalDateTime::createFromFormat('Y-m-d\TH:i:sP', $json_object['internal']['dateFilter']['dateRangeStart']);
      }
      if (!empty($json_object['internal']['dateFilter']['dateRangeEnd'])) {
        $default_date_range_end = DrupalDateTime::createFromFormat('Y-m-d\TH:i:sP', $json_object['internal']['dateFilter']['dateRangeEnd']);
      }
      $element['tabs']['content']['dateFilter']['dateRange']['dateRangeStart'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Date range start'),
        '#default_value' => $default_date_range_start,
      ];
      $element['tabs']['content']['dateFilter']['dateRange']['dateRangeEnd'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Date range end'),
        '#default_value' => $default_date_range_end,
      ];
    }
  }

  /**
   * Get all entity reference fields.
   *
   * Excluded the field_topic & field_tags as they are loaded manually.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param int $delta
   *   The current delta.
   * @param array $exclude_fields
   *   The list of entity reference fields to be excluded.
   *
   * @return array
   *   The reference fields.
   */
  protected function getEntityReferenceFields(FieldItemListInterface $items = NULL, $delta = NULL, array $exclude_fields = [
    'field_topic',
    'field_tags',
  ]) {
    $entity_reference_fields = $this->indexHelper->getIndexEntityReferenceFields($this->index, ['nid']);
    // Allow other modules to remove entity reference filters.
    $excludes = $this->moduleHandler->invokeAll('tide_content_collection_entity_reference_fields_exclude', [
      $this->index,
      $entity_reference_fields,
      !empty($items) ? clone $items : NULL,
      $delta,
    ]);
    if (!empty($exclude_fields)) {
      $excludes = array_merge($excludes, $exclude_fields);
    }
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

    $element['tabs']['layout']['display']['resultComponent']['style'] = [
      '#type' => 'radios',
      '#title' => $this->t('Card display style'),
      '#default_value' => $json_object['interface']['display']['resultComponent']['style'] ?? 'thumbnail',
      '#options' => [
        'no-image' => $this->t('No Image'),
        'thumbnail' => $this->t('Thumbnail'),
        'profile' => $this->t('Profile'),
      ],
    ];
    $internal_sort_options = [NULL => $this->t('Relevance')];
    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    if (!empty($date_fields)) {
      $internal_sort_options += $date_fields;
    }
    $string_fields = $this->indexHelper->getIndexStringFields($this->index);
    if (!empty($string_fields)) {
      $internal_sort_options += $string_fields;
    }
    $element['tabs']['layout']['internal']['sort']['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort content collection by'),
      '#default_value' => $json_object['internal']['sort']['field'] ?? 'title',
      '#options' => $internal_sort_options,
    ];

    $element['tabs']['layout']['internal']['sort']['direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort order'),
      '#default_value' => $json_object['internal']['sort']['direction'] ?? 'asc',
      '#options' => [
        'asc' => $this->t('Ascending (asc)'),
        'desc' => $this->t('Descending (desc)'),
      ],
    ];

  }

  /**
   * Build Filters Tab.
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
   * @param array $settings
   *   The settings of the listing.
   */
  protected function buildFiltersTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL, array $json_object = NULL, array $settings = []) {
    $settings = $this->getSettings();
    $element['tabs']['filters'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#title' => $this->t('Filters'),
      '#group_name' => 'tabs_filters',
    ];

    $element['tabs']['filters']['show_interface_filters'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable filtering'),
      '#default_value' => FALSE,
      '#weight' => 1,
    ];
    if (!empty($json_object['interface']['keyword']) || !empty($json_object['interface']['filters'])) {
      $element['tabs']['filters']['show_interface_filters']['#default_value'] = TRUE;
    }

    $element['tabs']['filters']['interface_filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'tabs_filters_interface_filters',
      '#weight' => 2,
      '#states' => [
        'visible' => [
          ':input[name="' . $this->getFormStatesElementName('tabs|filters|show_interface_filters', $items, $delta, $element) . '"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $element['tabs']['filters']['interface_filters']['keyword'] = [
      '#type' => 'details',
      '#title' => $this->t('Keyword'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'tabs_filters_interface_filters_keyword',
      '#weight' => 1,
    ];
    $element['tabs']['filters']['interface_filters']['keyword']['allow_keyword_search'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow keyword search'),
      '#default_value' => !empty($json_object['interface']['keyword']) ? TRUE : FALSE,
      '#weight' => 1,
    ];
    $element['tabs']['filters']['interface_filters']['keyword']['label'] = [
      '#title' => $this->t('Label'),
      '#type' => 'textfield',
      '#default_value' => $json_object['interface']['keyword']['label'] ?? $this->t("Search by keywords"),
      '#weight' => 2,
      '#states' => [
        'visible' => [
          ':input[name="' . $this->getFormStatesElementName('tabs|filters|interface_filters|keyword|allow_keyword_search', $items, $delta, $element) . '"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $element['tabs']['filters']['interface_filters']['keyword']['placeholder'] = [
      '#title' => $this->t('Placeholder text'),
      '#type' => 'textfield',
      '#default_value' => $json_object['interface']['keyword']['placeholder'] ?? $this->t("Enter keyword(s)"),
      '#weight' => 3,
      '#states' => [
        'visible' => [
          ':input[name="' . $this->getFormStatesElementName('tabs|filters|interface_filters|keyword|allow_keyword_search', $items, $delta, $element) . '"]' => ['checked' => TRUE],
        ],
      ],
    ];
    if (!empty($settings['filters']['enable_keyword_selection']) && $settings['filters']['enable_keyword_selection']) {
      $keyword_fields_options = [];
      $string_fields = $this->indexHelper->getIndexStringFields($this->index);
      if (!empty($string_fields)) {
        $keyword_fields_options += $string_fields;
      }
      $text_fields = $this->indexHelper->getIndexTextFields($this->index);
      if (!empty($text_fields)) {
        $keyword_fields_options += $text_fields;
      }
      $element['tabs']['filters']['interface_filters']['keyword']['fields'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Keyword fields'),
        '#options' => $keyword_fields_options,
        '#default_value' => $json_object['interface']['keyword']['fields'] ?? ['title'],
        '#weight' => 4,
        '#states' => [
          'visible' => [
            ':input[name="' . $this->getFormStatesElementName('tabs|filters|interface_filters|keyword|allow_keyword_search', $items, $delta, $element) . '"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $element['tabs']['filters']['interface_filters']['advanced_filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced filters'),
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#group_name' => 'tabs_filters_interface_filters_advanced_filters',
      '#description' => $this->t('Select additional fields to use as filters.'),
      '#weight' => 2,
    ];
    $entity_reference_fields = $this->getEntityReferenceFields(NULL, NULL, []);
    if (!empty($entity_reference_fields)) {
      $allowed_reference_fields = array_filter($settings['filters']['allowed_advanced_filters']);
      if (!empty($allowed_reference_fields)) {
        $entity_reference_fields = array_intersect_key($entity_reference_fields, array_flip($allowed_reference_fields));
      }
      $weight = 1;
      $field_data = [];
      if (!empty($json_object['interface']['filters']['fields'])) {
        $field_data = array_combine(array_column($json_object['interface']['filters']['fields'], 'elasticsearch-field'), $json_object['interface']['filters']['fields']);
      }
      foreach ($entity_reference_fields as $field_id => $field_label) {
        $element['tabs']['filters']['interface_filters']['advanced_filters'][$field_id]['allow'] = [
          '#type' => 'checkbox',
          '#title' => $field_label,
          '#default_value' => isset($field_data[$field_id]) ? TRUE : FALSE,
          '#weight' => $weight++,
        ];
        $element['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_label'] = [
          '#title' => $this->t('Label'),
          '#type' => 'textfield',
          '#default_value' => $field_data[$field_id]['options']['label'] ?? '',
          '#weight' => $weight++,
          '#states' => [
            'visible' => [
              ':input[name="' . $this->getFormStatesElementName('tabs|filters|interface_filters|advanced_filters|' . $field_id . '|allow', $items, $delta, $element) . '"]' => ['checked' => TRUE],
            ],
          ],
        ];
        $element['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_placeholder'] = [
          '#title' => $this->t('Placeholder text'),
          '#type' => 'textfield',
          '#default_value' => $field_data[$field_id]['options']['placeholder'] ?? '',
          '#weight' => $weight++,
          '#states' => [
            'visible' => [
              ':input[name="' . $this->getFormStatesElementName('tabs|filters|interface_filters|advanced_filters|' . $field_id . '|allow', $items, $delta, $element) . '"]' => ['checked' => TRUE],
            ],
          ],
        ];
        $default_values = [];
        if (!empty($field_data[$field_id]['options']['values'])) {
          foreach ($field_data[$field_id]['options']['values'] as $value) {
            $default_values[] = reset(array_keys($value));
          }
        }
        $field_filter = $this->indexHelper->buildEntityReferenceFieldFilter($this->index, $field_id, $default_values);
        if ($field_filter) {
          $element['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_options'] = $field_filter;
          $element['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_options']['#weight'] = $weight++;
          $element['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_options']['#title'] = $this->t('Options');
          $element['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_options']['#description'] = $this->t('Only show the selected options for this filter. If no option is selected, all available options will be shown.');
          $element['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_options']['#states'] = [
            'visible' => [
              ':input[name="' . $this->getFormStatesElementName('tabs|filters|interface_filters|advanced_filters|' . $field_id . '|allow', $items, $delta, $element) . '"]' => ['checked' => TRUE],
            ],
          ];
        }
      }
    }
  }

  /**
   * Build Advanced Tab.
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
  protected function buildAdvancedTab(FieldItemListInterface $items, $delta, array &$element, array &$form, FormStateInterface $form_state, array $configuration = NULL, array $json_object = NULL) {
    $element['tabs']['advanced'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#title' => $this->t('Advanced'),
      '#group_name' => 'advanced',
    ];

    $element['tabs']['advanced']['display']['options']['resultsCountText'] = [
      '#title' => $this->t('Show total number of results'),
      '#type' => 'textfield',
      '#description' => $this->t('
        Text to display above the results.<br/>
        This is read out to a screen reader when a search is performed.<br/>
        Supports 2 tokens:<br/>
        - {range} - The current range of results E.g. 1-12<br/>
        - {count} - The total count of results
      '),
      '#default_value' => $json_object['interface']['display']['options']['resultsCountText'] ?? $this->t('Displaying {range} of {count} results.'),
    ];

    $element['tabs']['advanced']['display']['options']['noResultsText'] = [
      '#title' => $this->t('No results message'),
      '#type' => 'textfield',
      '#description' => $this->t('Text to display when no results were returned.'),
      '#default_value' => $json_object['interface']['display']['options']['noResultsText'] ?? $this->t("Sorry! We couldn't find any matches."),
    ];

    $element['tabs']['advanced']['display']['options']['loadingText'] = [
      '#title' => $this->t('Loading message'),
      '#type' => 'textfield',
      '#description' => $this->t('Text to display when search results are loading.'),
      '#default_value' => $json_object['interface']['display']['options']['loadingText'] ?? $this->t('Loading'),
    ];

    $element['tabs']['advanced']['display']['options']['errorText'] = [
      '#title' => $this->t('Error message'),
      '#type' => 'textfield',
      '#description' => $this->t('Text to display when an error occurs.'),
      '#default_value' => $json_object['interface']['display']['options']['errorText'] ?? $this->t("Search isn't working right now, please try again later."),
    ];

    $element['tabs']['advanced']['display']['sort'] = [
      '#type' => 'details',
      '#title' => $this->t('Exposed sort options'),
      '#open' => FALSE,
      '#collapsible' => TRUE,
    ];

    $internal_sort_options = [NULL => $this->t('Relevance')];
    $date_fields = $this->indexHelper->getIndexDateFields($this->index);
    if (!empty($date_fields)) {
      $internal_sort_options += $date_fields;
    }
    $string_fields = $this->indexHelper->getIndexStringFields($this->index);
    if (!empty($string_fields)) {
      $internal_sort_options += $string_fields;
    }
    $element['tabs']['advanced']['display']['sort']['criteria'] = [
      '#type' => 'select',
      '#title' => $this->t('Select sort criteria'),
      '#options' => $internal_sort_options,
    ];
    $field_name = $this->fieldDefinition->getName();
    $element['tabs']['advanced']['display']['sort']['add_more'] = [
      '#type' => 'submit',
      '#name' => 'display_sort_criteria_add_more',
      '#value' => $this->t('Add'),
      '#attributes' => ['class' => ['field-add-more-submit']],
      '#limit_validation_errors' => [array_merge($form['#parents'], [$field_name])],
      '#submit' => [[$this, 'addSubmit']],
      '#ajax' => [
        'callback' => [$this, 'addAjax'],
        'wrapper' => 'display-sort-elements',
        'effect' => 'fade',
      ],
    ];
    $element['tabs']['advanced']['display']['sort']['elements'] = [
      '#type' => 'table',
      '#empty' => $this->t('No sort values set.'),
      '#header' => [
        $this->t('Field'),
        $this->t('Name'),
        $this->t('Direction'),
      ],
      '#prefix' => '<div id="display-sort-elements">',
      '#suffix' => '</div>',
    ];
    if (!empty($json_object['interface']['display']['sort']['values'])) {
      $element['tabs']['advanced']['display']['sort']['#open'] = TRUE;
      foreach ($json_object['interface']['display']['sort']['values'] as $key => $sort_element) {
        $value = $sort_element['value'] ?? [];
        $sort_field = [];
        $sort_field['field'] = [
          '#type' => 'textfield',
          '#default_value' => $value['field'] ?? NULL,
          '#disabled' => TRUE,
        ];
        $sort_field['name'] = [
          '#type' => 'textfield',
          '#default_value' => $sort_element['name'] ?? '',
        ];
        $sort_field['direction'] = [
          '#type' => 'select',
          '#default_value' => $value['direction'] ?? 'asc',
          '#options' => [
            'asc' => $this->t('Ascending'),
            'desc' => $this->t('Descending'),
          ],
        ];
        $element['tabs']['advanced']['display']['sort']['elements'][] = $sort_field;
      }
    }
    $element_add = $form_state->getValue('element_add') ?? FALSE;
    if ($element_add) {
      $input = $form_state->getValues();
      $field_name = $this->fieldDefinition->getName();
      $parents = $form['#parents'];
      $base_key = [
        $field_name,
        0,
      ];
      $input = $form_state->getValue(array_merge($parents, $base_key));
      $sort_field = [];
      $sort_field['field'] = [
        '#type' => 'textfield',
        '#default_value' => $input['tabs']['advanced']['display']['sort']['criteria'] ?? NULL,
        '#disabled' => TRUE,
      ];
      $sort_field['name'] = [
        '#type' => 'textfield',
      ];
      $sort_field['direction'] = [
        '#type' => 'select',
        '#options' => [
          'asc' => $this->t('Ascending'),
          'desc' => $this->t('Descending'),
        ],
      ];
      $element['tabs']['advanced']['display']['sort']['elements'][] = $sort_field;
      $form_state->setValue('element_add', FALSE);
    }
    $element['#attached']['library'][] = 'tide_content_collection/content_collection_ui_widget';

  }

  /**
   * Submit handler for the Add button.
   */
  public function addSubmit(array $form, FormStateInterface $form_state) {
    $form_state->setValue('element_add', TRUE);
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the Add button.
   */
  public function addAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
    return $element['elements'];

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
    $settings = $this->getSettings();
    foreach ($values as $delta => &$value) {
      $config = [];
      $config['title'] = $value['title'] ?? '';
      $config['description'] = $value['description'] ?? '';
      $config['callToAction']['text'] = $value['callToAction']['text'] ?? '';
      $config['callToAction']['url'] = $value['callToAction']['url'] ?? '';
      if (!$settings['content']['internal']['contentTypes']['enabled'] && !empty($settings['content']['internal']['contentTypes']['default_values'])) {
        $config['internal']['contentTypes'] = $settings['content']['internal']['contentTypes']['default_values'] ? array_values(array_filter($settings['content']['internal']['contentTypes']['default_values'])) : [];
      }
      elseif (!empty($value['tabs']['content']['contentTypes'])) {
        $config['internal']['contentTypes'] = $value['tabs']['content']['contentTypes'] ? array_values(array_filter($value['tabs']['content']['contentTypes'])) : [];
      }
      if (!$settings['content']['internal']['field_topic']['enabled'] && !empty($settings['content']['internal']['field_topic']['default_values'])) {
        $value['tabs']['content']['field_topic_wrapper']['field_topic'] = $settings['content']['internal']['field_topic']['default_values'] ?? [];
      }
      if (!empty($value['tabs']['content']['field_topic_wrapper']['field_topic'])) {
        foreach ($value['tabs']['content']['field_topic_wrapper']['field_topic'] as $index => $reference) {
          if (!empty($reference['target_id'])) {
            $config['internal']['contentFields']['field_topic']['values'][] = (int) $reference['target_id'];
          }
        }
        $config['internal']['contentFields']['field_topic']['operator'] = $value['tabs']['content']['field_topic_wrapper']['operator'] ?? 'OR';
      }
      if (!$settings['content']['internal']['field_tags']['enabled'] && !empty($settings['content']['internal']['field_tags']['default_values'])) {
        $value['tabs']['content']['field_tags_wrapper']['field_tags'] = $settings['content']['internal']['field_tags']['default_values'] ?? [];
      }
      if (!empty($value['tabs']['content']['field_tags_wrapper']['field_tags'])) {
        foreach ($value['tabs']['content']['field_tags_wrapper']['field_tags'] as $index => $reference) {
          if (!empty($reference['target_id'])) {
            $config['internal']['contentFields']['field_tags']['values'][] = (int) $reference['target_id'];
          }
        }
        $config['internal']['contentFields']['field_tags']['operator'] = $value['tabs']['content']['field_tags_wrapper']['operator'] ?? 'OR';
      }

      $entity_reference_fields = $this->getEntityReferenceFields();
      foreach ($value['tabs']['content']['advanced_filters'] as $wrapper_id => $wrapper) {
        $field_id = str_replace('_wrapper', '', $wrapper_id);
        if (!empty($settings['content']['internal'][$field_id]) && !$settings['content']['internal'][$field_id]['enabled'] && !empty($settings['content']['internal'][$field_id]['default_values'])) {
          $wrapper[$field_id] = $settings['content']['internal'][$field_id]['default_values'] ?? [];
        }
        if (!empty($wrapper[$field_id])) {
          // Entity reference fields.
          if (isset($entity_reference_fields[$field_id])) {
            foreach ($wrapper[$field_id] as $index => $reference) {
              if (!empty($reference['target_id'])) {
                $config['internal']['contentFields'][$field_id]['values'][] = (int) $reference['target_id'];
              }
            }
            unset($entity_reference_fields[$field_id]);
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

      if (!empty($entity_reference_fields)) {
        foreach ($entity_reference_fields as $field_id => $field_label) {
          if (!$settings['content']['internal'][$field_id]['enabled'] && !empty($settings['content']['internal'][$field_id]['default_values'])) {
            foreach ($settings['content']['internal'][$field_id]['default_values'] as $reference) {
              if (!empty($reference['target_id'])) {
                $config['internal']['contentFields'][$field_id]['values'][] = (int) $reference['target_id'];
              }
            }
          }
        }
      }

      // Date Filters.
      if (!empty($value['tabs']['content']['dateFilter']['criteria'])) {
        $config['internal']['dateFilter']['criteria'] = $value['tabs']['content']['dateFilter']['criteria'] ?? '';
        if ($value['tabs']['content']['dateFilter']['criteria'] == 'range') {
          $dateRangeStart = $value['tabs']['content']['dateFilter']['dateRange']['dateRangeStart'] ?? '';
          if ($dateRangeStart instanceof DrupalDateTime) {
            $config['internal']['dateFilter']['dateRangeStart'] = $dateRangeStart->format('c');
          }
          $dateRangeEnd = $value['tabs']['content']['dateFilter']['dateRange']['dateRangeEnd'] ?? '';
          if ($dateRangeEnd instanceof DrupalDateTime) {
            $config['internal']['dateFilter']['dateRangeEnd'] = $dateRangeEnd->format('c');
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
      $config['interface']['display']['resultComponent']['style'] = $value['tabs']['layout']['display']['resultComponent']['style'] ?? 'thumbnail';

      if (!empty($value['tabs']['layout']['internal']['sort']['field'])) {
        $config['internal']['sort']['field'] = $value['tabs']['layout']['internal']['sort']['field'] ?? '';
      }

      if (!empty($value['tabs']['layout']['internal']['sort']['direction'])) {
        $config['internal']['sort']['direction'] = $value['tabs']['layout']['internal']['sort']['direction'] ?? '';
      }

      // Filters Layout.
      $config['interface']['keyword']['label'] = $value['tabs']['filters']['interface_filters']['keyword']['label'] ?? '';
      $config['interface']['keyword']['placeholder'] = $value['tabs']['filters']['interface_filters']['keyword']['placeholder'] ?? '';
      if (!empty($settings['filters']['enable_keyword_selection']) && $settings['filters']['enable_keyword_selection']) {
        $config['interface']['keyword']['fields'] = $value['tabs']['filters']['interface_filters']['keyword']['fields'] ? array_values(array_filter($value['tabs']['filters']['interface_filters']['keyword']['fields'])) : [];
        ;
      }
      else {
        $config['interface']['keyword']['fields'] = ['title'];
      }

      if (!empty($value['tabs']['filters']['interface_filters']['advanced_filters'])) {
        $advanced_filters = array_keys($value['tabs']['filters']['interface_filters']['advanced_filters']);
        if (!empty($advanced_filters)) {
          foreach ($advanced_filters as $field_id) {
            $referenced_field = $this->indexHelper->getEntityReferenceFieldInfo($this->index, $field_id);
            if (!empty($value['tabs']['filters']['interface_filters']['advanced_filters'][$field_id]['allow']) && $value['tabs']['filters']['interface_filters']['advanced_filters'][$field_id]['allow']) {
              $field = [];
              $field['elasticsearch-field'] = $field_id;
              $field['options']['label'] = $value['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_label'] ?? '';
              $field['options']['placeholder'] = $value['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_placeholder'] ?? '';
              if (!empty($value['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_options'])) {
                foreach ($value['tabs']['filters']['interface_filters']['advanced_filters'][$field_id][$field_id . '_options'] as $index => $reference) {
                  if (!empty($reference['target_id'])) {
                    $target_id = (int) $reference['target_id'];
                    $entity = $this->entityTypeManager->getStorage($referenced_field['target_type'])->load($target_id);
                    if (!empty($entity)) {
                      $field['options']['values'][] = [$target_id => $entity->label()];
                    }
                  }
                }
              }
              $config['interface']['filters']['fields'][] = $field;
            }
          }
        }
      }

      // Advanced sort.
      if (!empty($value['tabs']['advanced']['display']['sort']['elements'])) {
        foreach ($value['tabs']['advanced']['display']['sort']['elements'] as $element) {
          if (!empty($element['name'])) {
            $sort_value = [];
            $sort_value['name'] = $element['name'];
            $sort_value['value'] = NULL;
            if (!empty($element['field'])) {
              $sort_value['value']['field'] = $element['field'] ?? NULL;
              $sort_value['value']['direction'] = $element['direction'] ?? 'asc';
            }
            $config['interface']['display']['sort']['values'][] = $sort_value;
          }
        }
      }

      // Advanced Layout.
      $config['interface']['display']['options']['resultsCountText'] = $value['tabs']['advanced']['display']['options']['resultsCountText'] ?? '';
      $config['interface']['display']['options']['noResultsText'] = $value['tabs']['advanced']['display']['options']['noResultsText'] ?? '';
      $config['interface']['display']['options']['loadingText'] = $value['tabs']['advanced']['display']['options']['loadingText'] ?? '';
      $config['interface']['display']['options']['errorText'] = $value['tabs']['advanced']['display']['options']['errorText'] ?? '';

      $value['value'] = json_encode($config);
    }
    return $values;
  }

}
