<?php

namespace Drupal\graphql_sdl\GraphQL;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataTrait;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql_sdl\Utility\DeferredUtility;
use Drupal\typed_data\DataFetcherTrait;
use GraphQL\Deferred;
use GraphQL\Type\Definition\ResolveInfo;

class ResolverBuilder {
  use TypedDataTrait;
  use DataFetcherTrait;

  /**
   * @param callable ...$resolvers
   *
   * @return \Closure
   */
  public function compose(callable ...$resolvers) {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) use ($resolvers) {
      while ($resolver = array_shift($resolvers)) {
        if (($value = $resolver($value, $args, $context, $info)) === NULL) {
          // Bail out early if a resolver returns NULL.
          return NULL;
        }

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
   * @param callable|null $source
   *
   * @return \Closure
   */
  public function context($name, callable $source = NULL) {
    return $this->tap(function ($value, $args, ResolveContext $context, ResolveInfo $info) use ($name, $source) {
      if (!isset($source)) {
        $source = $this->fromParent();
      }

      $value = $source($value, $args, $context, $info);
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
   * @param $type
   * @param $path
   * @param callable|NULL $value
   *
   * @return \Closure
   */
  public function fromPath($type, $path, callable $value = NULL) {
    return function ($parent, $args, ResolveContext $context, ResolveInfo $info) use ($type, $path, $value) {
      $value = $value ?? $this->fromParent();
      $value = $value($parent, $args, $context, $info);
      $metadata = new BubbleableMetadata();

      $type = $type instanceof DataDefinitionInterface ? $type : DataDefinition::create($type);
      $data = $this->getTypedDataManager()->create($type, $value);
      $output = $this->getDataFetcher()->fetchDataByPropertyPath($data, $path, $metadata)->getValue();

      $context->addCacheableDependency($metadata);
      if ($output instanceof CacheableDependencyInterface) {
        $context->addCacheableDependency($output);
      }

      return $output;
    };
  }

  /**
   * @param $value
   *
   * @return \Closure
   */
  public function fromValue($value) {
    return function ($parent, $args, ResolveContext $context, ResolveInfo $info) use ($value) {
      if ($value instanceof CacheableDependencyInterface) {
        $context->addCacheableDependency($value);
      }

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
      if ($value instanceof CacheableDependencyInterface) {
        $context->addCacheableDependency($value);
      }

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
      $output = $context->getContext($name, $info, $default);
      if ($output instanceof CacheableDependencyInterface) {
        $context->addCacheableDependency($output);
      }

      return $output;
    };
  }

}
