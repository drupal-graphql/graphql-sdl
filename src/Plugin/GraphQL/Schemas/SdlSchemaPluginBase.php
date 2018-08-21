<?php

namespace Drupal\graphql_sdl\Plugin\GraphQL\Schemas;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\Plugin\SchemaPluginInterface;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Utils\BuildSchema;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class SdlSchemaPluginBase extends PluginBase implements SchemaPluginInterface, ContainerFactoryPluginInterface, CacheableDependencyInterface {
  use RefinableCacheableDependencyTrait;

  /**
   * The cache bin for caching the parsed SDL.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $astCache;

  /**
   * Whether to use the schema cache.
   *
   * @var bool
   */
  protected $useCache;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('cache.graphql_sdl.ast'),
      $container->getParameter('graphql.config')
    );
  }

  /**
   * SdlSchemaPluginBase constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param array $pluginDefinition
   *   The plugin definition array.
   * @param \Drupal\Core\Cache\CacheBackendInterface $astCache
   *   The cache bin for caching the parsed SDL.
   * @param $config
   *   The service configuration.
   */
  public function __construct(
    $configuration,
    $pluginId,
    $pluginDefinition,
    CacheBackendInterface $astCache,
    $config
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);

    $this->useCache = empty($config['development']);
    $this->astCache = $astCache;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema() {
    $registry = $this->getResolverRegistry();

    return BuildSchema::build($this->getSchemaDocument(), function ($config, TypeDefinitionNode $type) use ($registry) {
      if ($type instanceof ObjectTypeDefinitionNode) {
        $config['resolveField'] = [$registry, 'resolveField'];
      }
      else if ($type instanceof InterfaceTypeDefinitionNode || $type instanceof UnionTypeDefinitionNode) {
        $config['resolveType'] = [$registry, 'resolveType'];
      }

      return $config;
    });
  }

  /**
   * Retrieves the parsed AST of the schema definition.
   *
   * @return \GraphQL\Language\AST\DocumentNode
   *   The parsed schema document.
   */
  protected function getSchemaDocument() {
    if (!empty($this->useCache) && $cache = $this->astCache->get($this->getPluginId())) {
      return $cache->data;
    }

    $ast = Parser::parse($this->getSchemaDefinition());
    if (!empty($this->useCache)) {
      $this->astCache->set($this->getPluginId(), CacheBackendInterface::CACHE_PERMANENT, ['graphql']);
    }

    return $ast;
  }

  /**
   * Retrieves the resolver registry.
   *
   * @return string
   *   The schema definition.
   */
  abstract protected function getResolverRegistry();

  /**
   * Retrieves the raw schema definition string.
   *
   * @return string
   *   The schema definition.
   */
  abstract protected function getSchemaDefinition();

}
