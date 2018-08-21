<?php

namespace Drupal\graphql_sdl\Utility;

use GraphQL\Deferred;

class DeferredUtility {

  /**
   * @param mixed $value
   * @param callable $callback
   *
   * @return \GraphQL\Deferred
   */
  public static function applyFinally($value, callable $callback) {
    // Recursively apply this function to deferred results.
    if ($value instanceof Deferred) {
      $value->then(function ($inner) use ($callback) {
        return static::applyFinally($inner, $callback);
      });
    }
    else {
      // This is the inner (non-deferred) result. Apply the callback.
      $callback($value);
    }

    return $value;
  }

  /**
   * @param $value
   * @param $return
   *
   * @return \GraphQL\Deferred
   */
  public static function returnFinal($value, $return) {
    return static::applyFinally($value, function () use ($return) {
      return $return;
    });
  }

}