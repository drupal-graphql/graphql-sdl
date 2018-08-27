<?php

namespace Drupal\graphql_sdl\Plugin\GraphQL\Schemas;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql\Plugin\SchemaPluginInterface;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
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
   * Whether the schema is currently in debugging mode.
   *
   * @var bool
   */
  protected $inDebug;

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

    $this->inDebug = !empty($config['development']);
    $this->astCache = $astCache;
  }

  /**
   * {@inheritdoc}
   */
  public function allowsQueryBatching() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function inDebug() {
    return $this->inDebug;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema() {
    return BuildSchema::build($this->getSchemaDocument(), function ($config, TypeDefinitionNode $type) {
      if ($type instanceof InterfaceTypeDefinitionNode || $type instanceof UnionTypeDefinitionNode) {
        $config['resolveType'] = $this->getTypeResolver($type);
      }

      return $config;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getRootValue() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    $registry = $this->getResolverRegistry();

    // Each document (e.g. in a batch query) gets its own resolve context. This
    // allows us to collect the cache metadata and contextual values (e.g.
    // inheritance for language) for each query separately.
    return function ($params, $document, $operation) use ($registry) {
      $context = new ResolveContext(['registry' => $registry]);
      $context->addCacheTags(['graphql_response']);
      if ($this instanceof CacheableDependencyInterface) {
        $context->addCacheableDependency($this);
      }

      return $context;
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeResolver(TypeDefinitionNode $type) {
    return function ($value, ResolveContext $context, ResolveInfo $info) {
      return $context->getGlobal('registry')->resolveType($value, $context, $info);
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldResolver() {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) {
      return $context->getGlobal('registry')->resolveField($value, $args, $context, $info);
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getValidationRules() {
    return function (OperationParams $params, DocumentNode $document, $operation) {
      if (isset($params->queryId)) {
        // Assume that pre-parsed documents are already validated. This allows
        // us to store pre-validated query documents e.g. for persisted queries
        // effectively improving performance by skipping run-time validation.
        return [];
      }

      return array_values(DocumentValidator::defaultRules());
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getPersistedQueryLoader() {
    return function ($id, OperationParams $params) {
      throw new RequestError('Persisted queries are currently not supported');
    };
  }

  /**
   * Retrieves the parsed AST of the schema definition.
   *
   * @return \GraphQL\Language\AST\DocumentNode
   *   The parsed schema document.
   */
  protected function getSchemaDocument() {
    if (!$this->inDebug() && $cache = $this->astCache->get($this->getPluginId())) {
      return $cache->data;
    }

    $ast = Parser::parse($this->getSchemaDefinition());
    if (!$this->inDebug()) {
      $this->astCache->set($this->getPluginId(), $ast, CacheBackendInterface::CACHE_PERMANENT, ['graphql']);
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
