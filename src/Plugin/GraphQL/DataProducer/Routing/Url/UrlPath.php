<?php

namespace Drupal\graphql_sdl\Plugin\GraphQL\DataProducer\Routing\Url;

use Drupal\Core\Url;
use Drupal\graphql\GraphQL\Cache\CacheableValue;
use Drupal\graphql_sdl\Plugin\GraphQL\DataProducer\DataProducerPluginBase;

/**
 * TODO: Fix the type of the input context.
 *
 * @DataProducer(
 *   id = "url_path",
 *   name = @Translation("Url path"),
 *   description = @Translation("The processed url path."),
 *   produces = @ContextDefinition("string",
 *     label = @Translation("Path")
 *   ),
 *   consumes = {
 *     "url" = @ContextDefinition("any",
 *       label = @Translation("Url")
 *     )
 *   }
 * )
 */
class UrlPath extends DataProducerPluginBase {

  /**
   * @param \Drupal\Core\Url $url
   *
   * @return \Drupal\graphql\GraphQL\Cache\CacheableValue
   */
  public function resolve(Url $url) {
    $url = $url->toString(TRUE);
    return new CacheableValue($url->getGeneratedUrl(), [$url]);
  }

}
