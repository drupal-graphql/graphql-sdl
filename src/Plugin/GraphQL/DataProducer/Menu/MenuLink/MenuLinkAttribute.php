<?php

namespace Drupal\graphql_sdl\Plugin\GraphQL\DataProducer\Menu\MenuLink;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\graphql_sdl\Plugin\GraphQL\DataProducer\DataProducerPluginBase;

/**
 * TODO: Fix input context type.
 *
 * @DataProducer(
 *   id = "menu_link_attribute",
 *   name = @Translation("Menu link attribute"),
 *   description = @Translation("Returns an attribute of a menu link."),
 *   produces = @ContextDefinition("string",
 *     label = @Translation("Attribute value")
 *   ),
 *   consumes = {
 *     "link" = @ContextDefinition("any",
 *       label = @Translation("Menu link")
 *     ),
 *     "attribute" = @ContextDefinition("string",
 *       label = @Translation("Attribute key")
 *     )
 *   }
 * )
 */
class MenuLinkAttribute extends DataProducerPluginBase {

  /**
   * @param \Drupal\Core\Menu\MenuLinkInterface $link
   * @param $attribute
   *
   * @return mixed
   */
  protected function resolve(MenuLinkInterface $link, $attribute) {
    return NestedArray::getValue( $link->getOptions(), ['attributes', $attribute]);
  }

}
