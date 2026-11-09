<?php

namespace Drupal\Tests\sentinel_connector\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Gives unit tests a string translation service that returns English.
 *
 * The connector translates its messages with t(), which reads the service
 * from the container. Unit tests have no container, so this sets one up.
 * Data providers run before any test method, so a provider that builds a
 * translated message calls setStringTranslationContainer() itself.
 */
trait TranslationStubTrait {

  /**
   * Sets a container with a pass-through string translation service.
   *
   * @before
   */
  #[Before]
  public function setUpStringTranslation(): void {
    self::setStringTranslationContainer();
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

  /**
   * Sets a container whose string translation returns the English text.
   */
  public static function setStringTranslationContainer(): void {
    $translation = new class() implements TranslationInterface {

      /**
       * {@inheritdoc}
       */
      public function translate($string, array $args = [], array $options = []): TranslatableMarkup {
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        return new TranslatableMarkup($string, $args, $options, $this);
      }

      /**
       * {@inheritdoc}
       */
      public function translateString(TranslatableMarkup $translated_string): string {
        return $translated_string->getUntranslatedString();
      }

      /**
       * {@inheritdoc}
       */
      public function formatPlural($count, $singular, $plural, array $args = [], array $options = []): PluralTranslatableMarkup {
        return new PluralTranslatableMarkup($count, $singular, $plural, $args, $options, $this);
      }

    };
    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);
  }

}
