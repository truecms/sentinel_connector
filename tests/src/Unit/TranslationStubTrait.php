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
       * Returns markup that this service translates.
       *
       * @param string $string
       *   The English text.
       * @param array<string, mixed> $args
       *   The placeholders.
       * @param array<string, mixed> $options
       *   The options.
       */
      public function translate($string, array $args = [], array $options = []): TranslatableMarkup {
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        return new TranslatableMarkup($string, $args, $options, $this);
      }

      /**
       * Returns the English text.
       */
      public function translateString(TranslatableMarkup $translated_string): string {
        return $translated_string->getUntranslatedString();
      }

      /**
       * Returns plural markup that this service translates.
       *
       * @param int $count
       *   The count.
       * @param string $singular
       *   The singular text.
       * @param string $plural
       *   The plural text.
       * @param array<string, mixed> $args
       *   The placeholders.
       * @param array<string, mixed> $options
       *   The options.
       */
      public function formatPlural($count, $singular, $plural, array $args = [], array $options = []): PluralTranslatableMarkup {
        return new PluralTranslatableMarkup($count, $singular, $plural, $args, $options, $this);
      }

      /**
       * Picks the English singular or plural form (Drupal 12).
       */
      public function selectPluralForm(int|float $count, string $string, ?string $langcode = NULL): string {
        $forms = explode(PluralTranslatableMarkup::DELIMITER, $string);
        return $count == 1 ? $forms[0] : ($forms[1] ?? $forms[0]);
      }

    };
    $container = new ContainerBuilder();
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);
  }

}
