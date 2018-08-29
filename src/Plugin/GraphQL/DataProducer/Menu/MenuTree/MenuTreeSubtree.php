<?php

namespace Drupal\graphql_sdl\Plugin\GraphQL\DataProducer\Menu\MenuTree;

use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\graphql_sdl\Plugin\GraphQL\DataProducer\DataProducerPluginBase;

/**
 * TODO: Fix input and output context type.
 *
 * @DataProducer(
 *   id = "menu_tree_subtree",
 *   name = @Translation("Menu tree subtree"),
 *   description = @Translation("Returns the subtree of a menu tree element."),
 *   produces = @ContextDefinition("any",
 *     label = @Translation("Menu link tree element"),
 *     multiple = TRUE
 *   ),
 *   consumes = {
 *     "element" = @ContextDefinition("any",
 *       label = @Translation("Menu link tree element")
 *     )
 *   }
 * )
 */
class MenuTreeSubtree extends DataProducerPluginBase {

  /**
   * @param \Drupal\Core\Menu\MenuLinkTreeElement $element
   *
   * @return mixed
   */
  protected function resolve(MenuLinkTreeElement $element) {
    return array_filter($element->subtree, function(MenuLinkTreeElement $item) {
      if ($item->link instanceof MenuLinkInterface) {
        return $item->link->isEnabled();
      }

      return TRUE;
    });
  }

}
