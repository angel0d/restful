<?php

/**
 * @file
 * Contains \Drupal\restful\Plugin\formatter\FormatterJson.
 */

namespace Drupal\restful\Plugin\formatter;

use Drupal\restful\Exception\InternalServerErrorException;
use Drupal\restful\Plugin\resource\Field\ResourceFieldCollectionInterface;
use Drupal\restful\Plugin\resource\Field\ResourceFieldInterface;
use Drupal\restful\Plugin\resource\Field\ResourceFieldResourceInterface;
use Drupal\restful\Plugin\resource\ResourceInterface;

/**
 * Class FormatterJsonApi
 * @package Drupal\restful\Plugin\formatter
 *
 * @Formatter(
 *   id = "json_api",
 *   label = "JSON API",
 *   description = "Output in using the JSON API format."
 * )
 */
class FormatterJsonApi extends Formatter implements FormatterInterface {

  /**
   * Content Type
   *
   * @var string
   */
  protected $contentType = 'application/vnd.api+json; charset=utf-8';

  /**
   * {@inheritdoc}
   */
  public function prepare(array $data) {
    // If we're returning an error then set the content type to
    // 'application/problem+json; charset=utf-8'.
    if (!empty($data['status']) && floor($data['status'] / 100) != 2) {
      $this->contentType = 'application/problem+json; charset=utf-8';
      return $data;
    }

    $output = array('data' => $this->extractFieldValues($data));

    if ($resource = $this->getResource()) {
      $request = $resource->getRequest();
      $data_provider = $resource->getDataProvider();
      if ($request->isListRequest($resource->getPath())) {
        // Get the total number of items for the current request without
        // pagination.
        $output['count'] = $data_provider->count();
      }
      if (method_exists($resource, 'additionalHateoas')) {
        $output = array_merge($output, $resource->additionalHateoas($output));
      }

      // Add HATEOAS to the output.
      $this->addHateoas($output);
    }

    return $output;
  }

  /**
   * Extracts the actual values from the resource fields.
   *
   * @param array[]|ResourceFieldCollectionInterface $data
   *   The array of rows or a ResourceFieldCollection.
   *
   * @return array[]
   *   The array of prepared data.
   *
   * @throws InternalServerErrorException
   */
  protected function extractFieldValues($data) {
    $output = array();
    foreach ($data as $public_field_name => $resource_field) {
      if (!$resource_field instanceof ResourceFieldInterface) {
        // If $resource_field is not a ResourceFieldInterface it means that we
        // are dealing with a nested structure of some sort. If it is an array
        // we process it as a set of rows, if not then use the value directly.
        $output[$public_field_name] = static::isIterable($resource_field) ? $this->extractFieldValues($resource_field) : $resource_field;
        continue;
      }
      if (!$data instanceof ResourceFieldCollectionInterface) {
        throw new InternalServerErrorException('Inconsistent output.');
      }
      $interpreter = $data->getInterpreter();
      $value = $resource_field->render($interpreter);
      // If the field points to a resource that can be included, include it
      // right away.
      if (
        static::isIterable($value) &&
        $resource_field instanceof ResourceFieldResourceInterface
      ) {
        $value = $this->extractFieldValues($value);
        $output['relationships'][$public_field_name] = array(
          'type' => $resource_field->getResourceMachineName(),
          'id' => $resource_field->getResourceId($interpreter),
        );
        $relationship = array(
          'attributes' => $value,
        );
        $relationship = $output['relationships'][$public_field_name] + $relationship;
        $this->addHateoas($relationship, $resource_field->getResourcePlugin());
        $output['included'][] = $relationship;
      }
      $output['data']['attributes'][$public_field_name] = $value;
    }
    return $output;
  }

  /**
   * Add HATEOAS links to list of item.
   *
   * @param array $data
   *   The data array after initial massaging.
   */
  protected function addHateoas(array &$data, ResourceInterface $resource = NULL) {
    $resource = $resource ?: $this->getResource();
    if (!$resource) {
      return;
    }
    $request = $resource->getRequest();

    if (!isset($data['links'])) {
      $data['links'] = array();
    }

    // Get self link.
    $data['links']['self'] = $resource->versionedUrl($resource->getPath());

    $input = $request->getParsedInput();
    $data_provider = $resource->getDataProvider();
    $page = !empty($input['page']) ? $input['page'] : 1;

    if ($page > 1) {
      $input['page'] = $page - 1;
      $data['links']['previous'] = $resource->getUrl();
    }

    // We know that there are more pages if the total count is bigger than the
    // number of items of the current request plus the number of items in
    // previous pages.
    $items_per_page = $data_provider->getRange();
    $previous_items = ($page - 1) * $items_per_page;
    if (isset($data['count']) && $data['count'] > count($data[$resource->getResourceMachineName()]) + $previous_items) {
      $input['page'] = $page + 1;
      $data['links']['next'] = $resource->getUrl();
    }

  }

  /**
   * {@inheritdoc}
   */
  public function render(array $structured_data) {
    return drupal_json_encode($structured_data);
  }

  /**
   * {@inheritdoc}
   */
  public function getContentTypeHeader() {
    return $this->contentType;
  }
}
