<?php

namespace Drupal\graphql_sdl\GraphQL;

use Drupal\graphql\GraphQL\Execution\ResolveContext;
use GraphQL\Type\Definition\ResolveInfo;

interface ResolverRegistryInterface {

  /**
   * @param $value
   * @param $args
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *
   * @return callable|null
   */
  public function resolveField($value, $args, ResolveContext $context, ResolveInfo $info);

  /**
   * @param $value
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *
   * @return callable|null
   */
  public function resolveType($value, ResolveContext $context, ResolveInfo $info);

}