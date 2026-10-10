<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Works out the health of pushes to Sentinel for the status report.
 */
class PushHealth {

  use StringTranslationTrait;

  /**
   * Seconds after which the last accepted push counts as too old.
   */
  public const STALE_AFTER = 86400;

  /**
   * Seconds cron is given to push once a plan limit has ended.
   *
   * On the Free plan the limit ends as the last accepted push turns a day
   * old. Without this allowance every such site would report an error from
   * that moment until its next cron run.
   */
  public const CRON_GRACE = 21600;

  /**
   * Severity of an informational entry; the value of REQUIREMENT_INFO.
   *
   * Core's constants live in install.inc, which is not loaded everywhere
   * this service can run, and their enum replacement needs Drupal 11.2.
   */
  public const SEVERITY_INFO = -1;

  /**
   * Severity of a healthy entry; the value of REQUIREMENT_OK.
   */
  public const SEVERITY_OK = 0;

  /**
   * Severity of a warning; the value of REQUIREMENT_WARNING.
   */
  public const SEVERITY_WARNING = 1;

  /**
   * Severity of an error; the value of REQUIREMENT_ERROR.
   */
  public const SEVERITY_ERROR = 2;

  /**
   * Constructs the push health service.
   *
   * @param \Drupal\sentinel_connector\SyncService $syncService
   *   The sync service, for the configuration check and the stored holds.
   * @param \Drupal\sentinel_connector\PushLimitFormatter $formatter
   *   Formats times in the site's time zone and names billing reasons.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store with the push bookkeeping.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $urlGenerator
   *   The URL generator, for the link to the settings form.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected SyncService $syncService,
    protected PushLimitFormatter $formatter,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected UrlGeneratorInterface $urlGenerator,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds the status report entry.
   *
   * Text that came from Sentinel is only passed as an escaped placeholder.
   *
   * @return array<string, mixed>
   *   A hook_requirements() entry: title, value, description and severity.
   */
  public function requirement(): array {
    $settingsUrl = $this->urlGenerator->generateFromRoute('sentinel_connector.settings');
    $entry = fn (int $severity, mixed $value, mixed $description): array => [
      'title' => $this->t('Sentinel Connector'),
      'value' => $value,
      'description' => $description,
      'severity' => $severity,
    ];

    if (!$this->syncService->isConfigured()) {
      return $entry(self::SEVERITY_ERROR, $this->t('Not configured'), $this->t('No data is sent to Sentinel. Set the API base URL, the site UUID and the API key on the <a href=":url">settings form</a>.', [':url' => $settingsUrl]));
    }

    $now = $this->time->getCurrentTime();
    $lastAccepted = (int) $this->state->get(SyncService::STATE_LAST_ACCEPTED_TIME, 0);
    // Cron does not push an unchanged inventory. A recent check that found
    // no change keeps an older accepted push current.
    $lastUnchanged = $lastAccepted > 0 ? (int) $this->syncService->getLastUnchangedTime() : 0;
    $unchanged = $lastUnchanged > $lastAccepted && ($now - $lastUnchanged) < self::STALE_AFTER;
    $fresh = $lastAccepted > 0 && (($now - $lastAccepted) < self::STALE_AFTER || $unchanged);
    $acceptedText = $lastAccepted > 0
      ? $this->t('Last push accepted: @time.', ['@time' => $this->formatter->formatTime($lastAccepted)])
      : $this->t('Sentinel has not accepted a push from this site yet.');
    if ($unchanged) {
      $acceptedText = $this->t('@accepted No change since; last checked @time.', [
        '@accepted' => $acceptedText,
        '@time' => $this->formatter->formatTime($lastUnchanged),
      ]);
    }

    // A billing refusal is an error however recent the last accepted push is:
    // no push succeeds until billing is fixed.
    if ($this->state->get('sentinel_connector.last_result') === SyncResult::SUBSCRIPTION_INACTIVE) {
      $reason = $this->state->get(SyncService::STATE_SUBSCRIPTION_REASON);
      $reason = is_string($reason) && $reason !== '' ? $reason : NULL;
      $message = $this->state->get('sentinel_connector.last_message', '');
      return $entry(
        self::SEVERITY_ERROR,
        $this->t('Push refused: subscription @reason', ['@reason' => $this->formatter->subscriptionReasonLabel($reason)]),
        $this->t('@message @accepted', [
          '@message' => $this->formatter->subscriptionError($reason, is_string($message) ? $message : ''),
          '@accepted' => $acceptedText,
        ]),
      );
    }

    $next = $this->syncService->getNextAllowedAt();
    if ($fresh) {
      if ($next !== NULL && $next > $now) {
        return $entry(self::SEVERITY_INFO, $this->t('Next push held by the plan limit'), $this->t('@accepted Sentinel accepts the next push after @next.', [
          '@accepted' => $acceptedText,
          '@next' => $this->formatter->formatTime($next),
        ]));
      }
      return $entry(self::SEVERITY_OK, $this->t('Last push accepted @time', ['@time' => $this->formatter->formatTime($lastAccepted)]), $unchanged
        ? $this->t('No change since; last checked @time. @cron', [
          '@time' => $this->formatter->formatTime($lastUnchanged),
          '@cron' => $this->cronNote($settingsUrl),
        ])
        : $this->cronNote($settingsUrl));
    }

    if ($lastAccepted === 0) {
      // Without a recorded time the site counts as just configured: cron
      // records the time on its next run.
      $configured = (int) $this->state->get(SyncService::STATE_CONFIGURED_TIME, 0);
      if ($configured === 0 || ($now - $configured) < self::STALE_AFTER) {
        return $entry(self::SEVERITY_WARNING, $this->t('No push yet'), $this->t('This site was configured less than 24 hours ago and Sentinel has not accepted a push from it yet. Run cron, or use "Sync now" on the <a href=":url">settings form</a>. @last', [
          ':url' => $settingsUrl,
          '@last' => $this->lastAttemptText(),
        ]));
      }
    }

    // The plan limit ends within a day, or ended a short while ago and cron
    // has not run since: the push is late because of the limit, not a fault.
    if ($next !== NULL && ($next - $now) <= self::STALE_AFTER && ($now - $next) < self::CRON_GRACE) {
      return $entry(self::SEVERITY_INFO, $this->t('Next push held by the plan limit'), $next > $now
        ? $this->t('@accepted Sentinel accepts the next push after @next.', [
          '@accepted' => $acceptedText,
          '@next' => $this->formatter->formatTime($next),
        ])
        : $this->t('@accepted The plan limit ended at @next. The next cron run sends a push.', [
          '@accepted' => $acceptedText,
          '@next' => $this->formatter->formatTime($next),
        ]));
    }

    return $entry(self::SEVERITY_ERROR, $this->t('No push accepted in the last 24 hours'), $this->t('@accepted @last Run cron and check the <a href=":url">settings form</a>. @cron', [
      '@accepted' => $acceptedText,
      '@last' => $this->lastAttemptText(),
      ':url' => $settingsUrl,
      '@cron' => $this->cronNote($settingsUrl),
    ]));
  }

  /**
   * Describes the last attempt: when it was made and how it ended.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The text. The stored result and message are escaped placeholders.
   */
  protected function lastAttemptText() {
    $lastAttempt = (int) $this->state->get('sentinel_connector.last_attempt_time', 0);
    if ($lastAttempt === 0) {
      return $this->t('No push has been attempted.');
    }
    $result = $this->state->get('sentinel_connector.last_result', 'unknown');
    $message = $this->state->get('sentinel_connector.last_message', '');
    return $this->t('Last attempt: @time, result: @result. @message', [
      '@time' => $this->formatter->formatTime($lastAttempt),
      '@result' => is_string($result) ? $result : 'unknown',
      '@message' => is_string($message) ? $message : '',
    ]);
  }

  /**
   * Says so when cron does not push because the setting is switched off.
   *
   * @param string $settingsUrl
   *   The URL of the settings form.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The note, or an empty string when automatic push is on.
   */
  protected function cronNote(string $settingsUrl) {
    if ($this->configFactory->get('sentinel_connector.settings')->get('enabled')) {
      return '';
    }
    return $this->t('Automatic push on cron is switched off on the <a href=":url">settings form</a>.', [':url' => $settingsUrl]);
  }

}
