<?php

namespace Drupal\sentinel_connector;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds the administrator-facing text for a push-limit outcome.
 *
 * Text from Sentinel is only ever passed as an escaped placeholder.
 */
class PushLimitFormatter {

  use StringTranslationTrait;

  /**
   * Constructs the formatter.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the site's default time zone.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected DateFormatterInterface $dateFormatter,
    protected ConfigFactoryInterface $configFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Formats a timestamp in the site's default time zone.
   *
   * @param int $timestamp
   *   The Unix timestamp.
   *
   * @return string
   *   The date and time with the time zone abbreviation.
   */
  public function formatTime(int $timestamp): string {
    $timezone = $this->configFactory->get('system.date')->get('timezone.default');
    // Without an explicit zone the formatter would use the current user's.
    $timezone = is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    return $this->dateFormatter->format($timestamp, 'custom', 'j M Y H:i T', $timezone);
  }

  /**
   * Builds the warning for a manual push that the push limit stopped.
   *
   * @param \Drupal\sentinel_connector\SyncResult $result
   *   A push-limit result.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The warning. The server message and the time are escaped placeholders.
   */
  public function warning(SyncResult $result): TranslatableMarkup {
    $arguments = ['@message' => $result->message];
    if ($result->nextAllowedAt === NULL) {
      return $this->t('Sentinel did not accept this push. @message Try again later.', $arguments);
    }
    $arguments['@time'] = $this->formatTime($result->nextAllowedAt);
    if ($result->deferred) {
      return $this->t('No push was sent, because Sentinel has already refused one. @message The next push will be accepted after @time.', $arguments);
    }
    return $this->t('Sentinel did not accept this push. @message The next push will be accepted after @time.', $arguments);
  }

}
