<?php

namespace Drupal\graphql_sdl\Plugin\GraphQL\Schemas;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql\Plugin\SchemaPluginInterface;
use GraphQL\Error\InvariantViolation;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\ServerConfig;
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
  public function validateSchema() {
    /** @var \Drupal\Core\Messenger\MessengerInterface $messenger */
    $messenger = \Drupal::service('messenger');
    $schema = $this->getSchema();

    try {
      $schema->assertValid();
    } catch (InvariantViolation $error) {
      $messenger->addError($error->getMessage());

      return FALSE;
    }

    $registry = $this->getResolverRegistry();
    if ($messages = $registry->validateCompliance($schema)) {
      foreach ($messages as $message) {
        $messenger->addError($message);
      }

      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getServer() {
    // Create the server config.
    $config = ServerConfig::create();
    $config->setContext($this->getContext());
    $config->setValidationRules($this->getValidationRules());
    $config->setPersistentQueryLoader($this->getPersistedQueryLoader());
    $config->setRootValue($this->getRootValue());
    $config->setQueryBatching($this->allowsQueryBatching());
    $config->setDebug($this->inDebug());
    $config->setSchema($this->getSchema());

    if ($resolver = $this->getFieldResolver()) {
      $config->setFieldResolver($resolver);
    }

    return $config;
  }

  /**
   * Returns whether the schema allows query batching.
   *
   * @return boolean
   *   TRUE if query batching is allowed, FALSE otherwise.
   */
  protected function allowsQueryBatching() {
    return TRUE;
  }

  /**
   * Returns whether the schema should output debugging information.
   *
   * Returning TRUE will add detailed error information to any error messages
   * returned during query execution.
   *
   * @return boolean
   *   TRUE if currently in development mode, FALSE otherwise.
   */
  protected function inDebug() {
    return $this->inDebug;
  }

  /**
   * Returns to root value to use when resolving queries against the schema.
   *
   * May return a callable to resolve the root value at run-time based on the
   * provided query parameters / operation.
   *
   * @code
   *
   * public function getRootValue() {
   *   return function (OperationParams $params, DocumentNode $document, $operation) {
   *     // Dynamically return a root value based on the current query.
   *   };
   * }
   *
   * @endcode
   *
   * @return mixed|callable
   *   The root value for query execution or a callable factory.
   */
  protected function getRootValue() {
    return NULL;
  }

  /**
   * Returns the context object to use during query execution.
   *
   * May return a callable to instantiate a context object for each individual
   * query instead of a shared context. This may be useful e.g. when running
   * batched queries where each query operation within the same request should
   * use a separate context object.
   *
   * The returned value will be passed as an argument to every type and field
   * resolver during execution.
   *
   * @code
   *
   * public function getContext() {
   *   $shared = ['foo' => 'bar'];
   *
   *   return function (OperationParams $params, DocumentNode $document, $operation) use ($shared) {
   *     $private = ['bar' => 'baz'];
   *
   *     return new MyContext($shared, $private);
   *   };
   * }
   *
   * @endcode
   *
   * @return mixed|callable
   *   The context object for query execution or a callable factory.
   */
  protected function getContext() {
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
  protected function getTypeResolver(TypeDefinitionNode $type) {
    return function ($value, ResolveContext $context, ResolveInfo $info) {
      return $context->getGlobal('registry')->resolveType($value, $context, $info);
    };
  }

  /**
   * Returns the default field resolver.
   *
   * Fields that don't explicitly declare a field resolver will use this one
   * as a fallback.
   *
   * @return null|callable
   *   The default field resolver.
   */
  protected function getFieldResolver() {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) {
      return $context->getGlobal('registry')->resolveField($value, $args, $context, $info);
    };
  }

  /**
   * Returns the validation rules to use for the query.
   *
   * May return a callable to allow the schema to decide the validation rules
   * independently for each query operation.
   *
   * @code
   *
   * public function getValidationRules() {
   *   return function (OperationParams $params, DocumentNode $document, $operation) {
   *     if (isset($params->queryId)) {
   *       // Assume that pre-parsed documents are already validated. This allows
   *       // us to store pre-validated query documents e.g. for persisted queries
   *       // effectively improving performance by skipping run-time validation.
   *       return [];
   *     }
   *
   *     return array_values(DocumentValidator::defaultRules());
   *   };
   * }
   *
   * @endcode
   *
   * @return array|callable
   *   The validation rules or a callable factory.
   */
  protected function getValidationRules() {
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
   * Returns a callable for loading persisted queries.
   *
   * @return callable
   *   The persisted query loader.
   */
  protected function getPersistedQueryLoader() {
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
    // Only use caching of the parsed document if aren't in development mode.
    $useCache = !$this->inDebug();

    if (!empty($useCache) && $cache = $this->astCache->get($this->getPluginId())) {
      return $cache->data;
    }

    $ast = Parser::parse($this->getSchemaDefinition());
    if (!empty($useCache)) {
      $this->astCache->set($this->getPluginId(), $ast, CacheBackendInterface::CACHE_PERMANENT, ['graphql']);
    }

    return $ast;
  }

  /**
   * Retrieves the resolver registry.
   *
   * @return \Drupal\graphql_sdl\GraphQL\ResolverRegistryInterface
   *   The resolver registry.
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
