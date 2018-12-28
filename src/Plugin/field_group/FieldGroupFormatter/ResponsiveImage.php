<?php
/**
 * Created by PhpStorm.
 * Filename: ResponsiveImage.php
 * Descr: Some description
 * User: nelsonpireshassanali
 * Date: 27/12/2018
 * Time: 16:56
 */

namespace Drupal\field_group_responsive_image\Plugin\field_group\FieldGroupFormatter;

use Drupal\breakpoint\Breakpoint;
use Drupal\Core\Render\Element;
use Drupal\Core\Template\Attribute;
use Drupal\field_group\FieldGroupFormatterBase;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;

/**
 * Plugin implementation of the 'background image' formatter.
 *
 * @FieldGroupFormatter(
 *   id = "responsive_image",
 *   label = @Translation("Responsive image"),
 *   description = @Translation("Field group as picture element."),
 *   supported_contexts = {
 *     "view",
 *   }
 * )
 */
class ResponsiveImage extends FieldGroupFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function preRender(&$element, $renderingObject) {

    $this->hideChildren($element);

    $attributes = new Attribute();

    // Add the HTML ID.
    if ($id = $this->getSetting('id')) {
      $attributes['id'] = Html::getId($id);
    }

    // Add the HTML classes.
    $attributes['class'] = $this->getClasses();

    // Render the element as a HTML div and add the attributes.
    $element['#type'] = 'container';

    $sources = [];

    foreach ($this->getBreakpoints() as $breakpoint) {
      $multipliers = [];
      foreach ($breakpoint->getMultipliers() as $multiplier) {
        $key = implode('_', [
          'image',
          $breakpoint->getPluginId(),
          $multiplier,
        ]);
        $key = str_replace('.', '_', $key);

        if ($image_field = $this->getSetting($key)) {
          $multipliers[$multiplier] = $image_field;
        }
      }
      if (!empty($multipliers)) {
        $sources[] = $this->getSource($renderingObject, $breakpoint, $multipliers);
      }
    }

    $element['picture'] = [
      '#type'     => 'inline_template',
      '#template' => '{% include "@stable/field/responsive-image.html.twig" %}',
      '#context'  => [
        'sources'          => $sources,
        'output_image_tag' => empty($sources),
      ],
    ];

    if ($image = $this->getSetting('image_fallback')) {
      if ($imageFile = $this->imageFile($renderingObject, $image)) {
        $element['picture']['#context']['img_element'] = [
          '#theme' => 'image',
          '#uri'   => $imageFile->getFileUri(),
          '#alt'   => $imageFile->getFilename(),
        ];
      }

    }

    $element['#attributes'] = $attributes;
  }

  /**
   * @param $element
   */
  protected function hideChildren(&$element) {
    foreach (Element::getVisibleChildren($element) as $child) {
      $element[$child]['#access'] = FALSE;
    }
  }

  /**
   * @return \Drupal\breakpoint\Breakpoint[]
   */
  protected function getBreakpoints() {
    $default_theme = \Drupal::config('system.theme')->get('default');

    return \Drupal::service('breakpoint.manager')
      ->getBreakpointsByGroup($default_theme);
  }

  /**
   * @param $renderingObject
   * @param \Drupal\breakpoint\Breakpoint $breakpoint
   * @param $multipliers
   *
   * @return bool|\Drupal\Core\Template\Attribute
   */
  protected function getSource($renderingObject, Breakpoint $breakpoint, $multipliers) {

    $srcset = [];

    foreach ($multipliers as $multiplier => $field) {
      if (($imageUrl = $this->imageUrl($renderingObject, $field))) {
        $srcset[] = sprintf('%s %s', $imageUrl, $multiplier);
      }
    }

    if (empty($srcset)) {
      return FALSE;
    }

    $source = [
      'srcset' => implode(', ', $srcset),
      'media'  => $breakpoint->getMediaQuery(),
    ];

    return new Attribute($source);

  }

  /**
   * Returns an image URL to be used in the Field Group.
   *
   * @param object $renderingObject
   *   The object being rendered.
   * @param string $field
   *   Image field name.
   * @param string $imageStyle
   *   Image style name.
   *
   * @return string
   *   Image URL.
   */
  protected function imageUrl($renderingObject, $field, $imageStyle = NULL) {
    $imageUrl = '';

    if (empty(($file = $this->imageFile($renderingObject, $field)))) {
      return $imageUrl;
    }

    // When no image style is selected, use the original image.
    if (empty($imageStyle)) {
      $imageUrl = file_create_url($file->getFileUri());
    }
    else {
      $imageUrl = ImageStyle::load($imageStyle)->buildUrl($file->getFileUri());
    }

    return file_url_transform_relative($imageUrl);
  }

  /**
   * @param $renderingObject
   * @param $field
   * @param null $imageStyle
   *
   * @return bool|\Drupal\Core\Entity\EntityInterface|\Drupal\file\Entity\File|null
   */
  protected function imageFile($renderingObject, $field, $imageStyle = NULL) {
    $file = FALSE;
    /* @var EntityInterface $entity */
    if (!($entity = $renderingObject['#' . $this->group->entity_type])) {
      return $file;
    }

    if ($imageFieldValue = $renderingObject['#' . $this->group->entity_type]->get($field)
      ->getValue()) {

      // Fid for image or entity_id.
      if (!empty($imageFieldValue[0]['target_id'])) {
        $entity_id = $imageFieldValue[0]['target_id'];

        $fieldDefinition = $entity->getFieldDefinition($field);
        // Get the media or file URI.
        if (
          $fieldDefinition->getType() == 'entity_reference' &&
          $fieldDefinition->getSetting('target_type') == 'media'
        ) {

          // Load media.
          $entity_media = Media::load($entity_id);

          // Loop over entity fields.
          foreach ($entity_media->getFields() as $field_name => $field) {
            if (
              $field->getFieldDefinition()->getType() === 'image' &&
              $field->getFieldDefinition()->getName() !== 'thumbnail'
            ) {
              $file = $entity_media->{$field_name}->entity;
            }
          }
        }
        else {
          $file = File::load($entity_id);
        }
      }
    }

    return $file;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm() {

    $form = parent::settingsForm();

    if ($imageFields = $this->imageFields()) {

      foreach ($this->getBreakpoints() as $breakpoint) {
        foreach ($breakpoint->getMultipliers() as $multiplier) {
          $key                    = implode('_', [
            'image',
            $breakpoint->getPluginId(),
            $multiplier,
          ]);
          $key                    = str_replace('.', '_', $key);
          $form[$key]             = [
            '#title'         => $this->t('Image for the breakpoint (@name : @multiplier - @srcset)', [
              '@name'       => $breakpoint->getLabel(),
              '@multiplier' => $multiplier,
              '@srcset'     => $breakpoint->getMediaQuery(),
            ]),
            '#type'          => 'select',
            '#options'       => [
              '' => $this->t('- Select -'),
            ],
            '#default_value' => $this->getSetting($key),
            '#weight'        => 1,
          ];
          $form[$key]['#options'] += $imageFields;
        }
      }

      $key                    = 'image_fallback';
      $form[$key]             = [
        '#title'         => $this->t('Fallback Image'),
        '#type'          => 'select',
        '#options'       => [
          '' => $this->t('- Select -'),
        ],
        '#default_value' => $this->getSetting($key),
        '#weight'        => 1,
      ];
      $form[$key]['#options'] += $imageFields;

    }


    return $form;

  }

  /**
   * Get all image fields for the current entity and bundle.
   *
   * @return array
   *   Image field key value pair.
   */
  protected function imageFields() {

    $entityFieldManager = \Drupal::service('entity_field.manager');
    $fields             = $entityFieldManager->getFieldDefinitions($this->group->entity_type, $this->group->bundle);

    $imageFields = [];
    foreach ($fields as $field) {
      if ($field->getType() === 'image' || ($field->getType() === 'entity_reference' && $field->getSetting('target_type') == 'media')) {
        $imageFields[$field->get('field_name')] = $field->label();
      }
    }

    return $imageFields;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $imageFields = $this->imageFields();

    foreach ($this->getBreakpoints() as $breakpoint) {
      foreach ($breakpoint->getMultipliers() as $multiplier) {
        $key = implode('_', [
          'image',
          $breakpoint->getPluginId(),
          $multiplier,
        ]);
        $key = str_replace('.', '_', $key);

        if ($image = $this->getSetting($key)) {
          $summary[] = $this->t('Image (@name : @multiplier - @srcset) : @image', [
            '@image'      => $imageFields[$image],
            '@name'       => $breakpoint->getLabel(),
            '@multiplier' => $multiplier,
            '@srcset'     => $breakpoint->getMediaQuery(),
          ]);
        }
      }

    }


    if ($image = $this->getSetting('image_fallback')) {
      $summary[] = $this->t('Image fallback: @image', ['@image' => $imageFields[$image]]);
    }

    return $summary;
  }

}
