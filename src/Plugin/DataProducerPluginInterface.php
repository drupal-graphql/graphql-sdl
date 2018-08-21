<?php

namespace Drupal\graphql_sdl\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use GraphQL\Type\Definition\ResolveInfo;

interface DataProducerPluginInterface extends PluginInspectionInterface, DerivativeInspectionInterface {

}
