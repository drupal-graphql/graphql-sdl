<?php

namespace Drupal\graphql_sdl\GraphQL;

use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql_sdl\Utility\DeferredUtility;
use GraphQL\Deferred;
use GraphQL\Type\Definition\ResolveInfo;

class ResolverBuilder {

  /**
   * @param callable ...$resolvers
   *
   * @return \Closure
   */
  public function compose(callable ...$resolvers) {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) use ($resolvers) {
      while ($resolver = array_shift($resolvers)) {
        $value = $resolver($value, $args, $context, $info);

        if ($value instanceof Deferred) {
          return DeferredUtility::applyFinally($value, function ($value) use ($resolvers, $args, $context, $info) {
            return $this->compose(...$resolvers)($value, $args, $context, $info);
          });
        }
      }

      return $value;
    };
  }

  /**
   * @param callable $callback
   *
   * @return \Closure
   */
  public function tap(callable $callback) {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) use ($callback) {
      $callback($value, $args, $context, $info);
      return $value;
    };
  }

  /**
   * @param $name
   * @param string $source
   *
   * @return \Closure
   */
  public function context($name, $source = 'parent') {
    return $this->tap(function ($value, $args, ResolveContext $context, ResolveInfo $info) use ($name, $source) {
      $context->setContext($name, $value, $info);
    });
  }

  /**
   * @param $id
   * @param $config
   *
   * @return callable
   */
  public function produce($id, $config = []) {
    // TODO: Properly inject this.
    $manager = \Drupal::service('plugin.manager.graphql_sdl.data_producer');
    $plugin = $manager->getInstance(['id' => $id, 'configuration' => $config]);

    if (!is_callable($plugin)) {
      throw new \LogicException(sprintf('Plugin %s is not callable.', $id));
    }

    return $plugin;
  }

  /**
   * @param $value
   *
   * @return \Closure
   */
  public function fromValue($value) {
    return function () use ($value) {
      return $value;
    };
  }

  /**
   * @param $name
   *
   * @return \Closure
   */
  public function fromArgument($name) {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) use ($name) {
      return $args[$name] ?? NULL;
    };
  }

  /**
   * @return \Closure
   */
  public function fromParent() {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) {
      return $value;
    };
  }

  /**
   * @param $name
   * @param callable|null $default
   *
   * @return \Closure
   */
  public function fromContext($name, $default = NULL) {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) use ($name, $default) {
      return $context->getContext($name, $info, $default);
    };
  }

}
