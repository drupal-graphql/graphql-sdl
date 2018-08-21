<?php

namespace Drupal\graphql_sdl\Plugin\GraphQL\DataProducer\Entity;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql_sdl\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use GraphQL\Deferred;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DataProducer(
 *   id = "entity_load",
 *   name = @Translation("Load entity"),
 *   description = @Translation("Loads a single entity."),
 *   produces = @ContextDefinition("entity",
 *     label = @Translation("Entity")
 *   ),
 *   consumes = {
 *     "entity_type" = @ContextDefinition("string",
 *       label = @Translation("Entity type")
 *     ),
 *     "entity_bundle" = @ContextDefinition("string",
 *       label = @Translation("Entity bundle(s)"),
 *       multiple = TRUE,
 *       required = FALSE
 *     ),
 *     "entity_id" = @ContextDefinition("string",
 *       label = @Translation("Identifier")
 *     )
 *   }
 * )
 */
class Load extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * EntityLoad constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param array $pluginDefinition
   *   The plugin definition array.
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    $configuration,
    $pluginId,
    $pluginDefinition,
    EntityTypeManager $entityTypeManager
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * @param $entityType
   * @param $entityBundles
   * @param $entityId
   *
   * @return mixed
   */
  public function resolve($entityType, $entityBundles, $entityId) {
    return new Deferred(function () use ($entityType, $entityBundles, $entityId) {
      $storage = $this->entityTypeManager->getStorage($entityType);
      if (!$entity = $storage->load($entityId)) {
        return NULL;
      }

      if (isset($entityBundles) && !in_array($entity->bundle(), $entityBundles)) {
        return NULL;
      }

      return $entity;
    });
  }
}
