<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Gives unit tests a string translation service that returns English.
 *
 * The connector translates its messages with t(), which reads the service
 * from the container. Unit tests have no container, so this sets one up.
 */
trait TranslationStubTrait {

  /**
   * Sets a container with a pass-through string translation service.
   *
   * @before
   */
  #[Before]
  public function setUpStringTranslation(): void {
    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(
      static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString(),
    );
    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);
  }

  /**
   * Removes the container again.
   *
   * @after
   */
  #[After]
  public function tearDownStringTranslation(): void {
    \Drupal::unsetContainer();
  }

}
