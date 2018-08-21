<?php

namespace Drupal\graphql_sdl\GraphQL;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use GraphQL\Executor\Executor;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

class ResolverRegistry implements ResolverRegistryInterface {

  /**
   * Nested list of field resolvers.
   *
   * Contains a nested list of callables, keyed by type and field name.
   *
   * @var callable[]
   */
  protected $fieldResolvers = [];

  /**
   * List of type resolvers for abstract types.
   *
   * Contains a list of callables keyed by the name of the abstract type.
   *
   * @var callable[]
   */
  protected $typeResolvers = [];

  /**
   * The type definitions.
   *
   * @var \Drupal\Core\Plugin\Context\ContextDefinition[]
   */
  protected $dataTypes = [];

  /**
   * The default field resolver.
   *
   * Used as a fallback if a specific field resolver can't be found.
   *
   * @var callable
   */
  protected $defaultFieldResolver;

  /**
   * The default type resolver.
   *
   * Used as a fallback if a specific type resolver can't be found.
   *
   * @var callable
   */
  protected $defaultTypeResolver;

  /**
   * ResolverRegistry constructor.
   *
   * @param $dataTypes
   * @param callable|null $defaultFieldResolver
   * @param callable|null $defaultTypeResolver
   */
  public function __construct($dataTypes, callable $defaultFieldResolver = NULL, callable $defaultTypeResolver = NULL) {
    $this->dataTypes = $dataTypes;
    $this->defaultFieldResolver = $defaultFieldResolver ?: [$this, 'resolveFieldDefault'];
    $this->defaultTypeResolver = $defaultTypeResolver ?: [$this, 'resolveTypeDefault'];
  }

  /**
   * @param string $type
   * @param string $field
   * @param callable $resolver
   *
   * @return $this
   */
  public function addFieldResolver($type, $field, callable $resolver) {
    $this->fieldResolvers[$type][$field] = $resolver;
    return $this;
  }

  /**
   * @param $value
   * @param $args
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *
   * @return callable|null
   */
  public function getFieldResolver($value, $args, ResolveContext $context, ResolveInfo $info) {
    if (isset($this->fieldResolvers[$info->parentType->name][$info->fieldName])) {
      return $this->fieldResolvers[$info->parentType->name][$info->fieldName];
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveField($value, $args, ResolveContext $context, ResolveInfo $info) {
    // First, check if there is a resolver registered for this field.
    if ($resolver = $this->getFieldResolver($value, $args, $context, $info)) {
      if (!is_callable($resolver)) {
        throw new \LogicException(sprintf('Field resolver for field %s on type %s is not callable.', $info->fieldName, $info->parentType->name));
      }

      return $resolver($value, $args, $context, $info);
    }

    return call_user_func($this->defaultFieldResolver, $value, $args, $context, $info);
  }

  /**
   * @param $value
   * @param $args
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *
   * @return mixed|null|string
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function resolveFieldDefault($value, $args, ResolveContext $context, ResolveInfo $info) {
    $data = $value instanceof EntityInterface ? $value->getTypedData() : $value;
    if ($data instanceof ComplexDataInterface) {
      if (array_key_exists($info->fieldName, $data->getProperties())) {
        return $data->get($info->fieldName)->getString();
      }
    }

    // Fall back to the default field resolver. Note that this is NOT the one
    // potentially registered with the ExecutionContext because we can't access
    // it. It's marked private.
    if (($output = Executor::defaultFieldResolver($value, $args, $context, $info)) !== NULL) {
      return $output;
    }

    return NULL;
  }

  /**
   * @param $abstract
   * @param callable $resolver
   *
   * @return $this
   */
  public function addTypeResolvers($abstract, callable $resolver) {
    $this->typeResolvers[$abstract] = $resolver;
    return $this;
  }

  /**
   * @param $value
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *
   * @return callable|mixed|null
   */
  public function getTypeResolver($value, ResolveContext $context, ResolveInfo $info) {
    /** @var \GraphQL\Type\Definition\InterfaceType|\GraphQL\Type\Definition\UnionType $abstract */
    $abstract = $info->returnType;

    if (isset($this->typeResolvers[$abstract->name])) {
      return $this->typeResolvers[$abstract->name];
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveType($value, ResolveContext $context, ResolveInfo $info) {
    // First, check if there is a resolver registered for this abstract type.
    if ($resolver = $this->getTypeResolver($value, $context, $info)) {
      if (!is_callable($resolver)) {
        throw new \LogicException(sprintf('Type resolver for type %s is not callable.', $info->parentType->name));
      }

      if (($type = $resolver($value, $context, $info)) !== NULL) {
        return $type;
      }
    }

    return call_user_func($this->defaultTypeResolver, $value, $context, $info);
  }

  /**
   * @param $value
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *
   * @return null
   */
  protected function resolveTypeDefault($value, ResolveContext $context, ResolveInfo $info) {
    $abstract = Type::getNamedType($info->returnType);
    $types = $info->schema->getPossibleTypes($abstract);

    foreach ($types as $type) {
      $name = $type->name;

      // TODO: Warn about performance impact of generic type resolution?
      if (isset($this->dataTypes[$name]) && $definition = $this->dataTypes[$name]) {
        if ($definition->isSatisfiedBy(new Context($definition, $value))) {
          return $name;
        }
      }
    }

    return NULL;
  }


}