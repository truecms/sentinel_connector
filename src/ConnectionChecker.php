<?php

namespace Drupal\sentinel_connector;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs "Test connection" and drush sentinel_connector:check.
 *
 * Resolves the settings a push would use, asks Sentinel's connection check
 * route whether it accepts them, records the outcome for the status report
 * and words it in one plain answer.
 */
class ConnectionChecker {

  use StringTranslationTrait;

  /**
   * State key: the outcome of the last connection check.
   *
   * An array with 'outcome', 'message' and 'time'.
   */
  public const STATE_LAST_CHECK = 'sentinel_connector.last_check';

  /**
   * The knowledge base article on several environments.
   */
  public const ENVIRONMENTS_HELP_URL = 'https://sentinelconnector.io/app/support/knowledge-base/use-the-sentinel-connector-on-several-environments';

  /**
   * Constructs the checker.
   *
   * @param \Drupal\sentinel_connector\SentinelClient $client
   *   The HTTP client for the Sentinel backend.
   * @param \Drupal\sentinel_connector\ApiBaseUrlResolver $urlResolver
   *   Resolves the API base URL.
   * @param \Drupal\sentinel_connector\SiteUuidResolver $uuidResolver
   *   Resolves the Site UUID.
   * @param \Drupal\sentinel_connector\ApiKeyResolver $apiKeyResolver
   *   Resolves the API key.
   * @param \Drupal\sentinel_connector\SendingSwitch $sendingSwitch
   *   The off switch.
   * @param \Drupal\sentinel_connector\PushLimitFormatter $pushLimitFormatter
   *   Words a push limit or an inactive subscription.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store, for the last outcome.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger; never receives the API key.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected SentinelClient $client,
    protected ApiBaseUrlResolver $urlResolver,
    protected SiteUuidResolver $uuidResolver,
    protected ApiKeyResolver $apiKeyResolver,
    protected SendingSwitch $sendingSwitch,
    protected PushLimitFormatter $pushLimitFormatter,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Checks the connection with the settings a push would use.
   *
   * Nothing is sent when sending is turned off, when the API base URL is not
   * valid, or when the Site UUID or the API key is missing.
   */
  public function check(): ConnectionCheckResult {
    if ($this->sendingSwitch->isDisabled()) {
      return new ConnectionCheckResult(ConnectionCheckResult::DISABLED);
    }
    $url = $this->urlResolver->getUrl();
    $uuid = $this->uuidResolver->getUuid();
    $key = $this->apiKeyResolver->getKey();
    if ($url === NULL) {
      $result = new ConnectionCheckResult(ConnectionCheckResult::INVALID_URL);
    }
    elseif ($uuid === NULL || $key === NULL) {
      $result = new ConnectionCheckResult(ConnectionCheckResult::UNCONFIGURED);
    }
    else {
      try {
        $result = $this->client->check($url, $uuid, $key);
      }
      catch (\Throwable) {
        $result = new ConnectionCheckResult(ConnectionCheckResult::NOT_SENTINEL);
      }
    }
    // Plain text: the status report escapes it again.
    $message = trim(strip_tags(html_entity_decode((string) $this->message($result), ENT_QUOTES | ENT_HTML5)));
    $this->state->set(self::STATE_LAST_CHECK, [
      'outcome' => $result->outcome,
      'message' => $message,
      'time' => $this->time->getRequestTime(),
    ]);
    try {
      $this->logger->log($result->isOk() ? 'info' : 'warning', 'Sentinel connection check: @outcome. @message', [
        '@outcome' => $result->outcome,
        '@message' => $message,
      ]);
    }
    catch (\Throwable) {
      // Broken log storage must not change the outcome of the check.
    }
    return $result;
  }

  /**
   * The last recorded check, when there is one.
   *
   * @return array{outcome: string, message: string, time: int}|null
   *   The outcome, its message and the time of the check.
   */
  public function lastCheck(): ?array {
    $last = $this->state->get(self::STATE_LAST_CHECK);
    if (!is_array($last) || !is_string($last['outcome'] ?? NULL)) {
      return NULL;
    }
    return [
      'outcome' => $last['outcome'],
      'message' => is_string($last['message'] ?? NULL) ? $last['message'] : '',
      'time' => (int) ($last['time'] ?? 0),
    ];
  }

  /**
   * Words the outcome of a check in one plain answer.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The answer. Server text and configured values are escaped placeholders.
   */
  public function message(ConnectionCheckResult $result): TranslatableMarkup {
    $sentinel = match ($result->environment) {
      'production' => $this->t('Sentinel production'),
      'uat' => $this->t('Sentinel UAT'),
      'local' => $this->t('Sentinel local'),
      default => $this->t('Sentinel'),
    };
    $url = (string) ($this->urlResolver->getUrl() ?? $this->urlResolver->getDisplayValue());
    switch ($result->outcome) {
      case ConnectionCheckResult::CONNECTED:
        return $this->t('Connected to @sentinel as this site. The API key and Site UUID are accepted.', ['@sentinel' => $sentinel]);

      case ConnectionCheckResult::DISABLED:
        return $this->t('@message', ['@message' => $this->sendingSwitch->message()]);

      case ConnectionCheckResult::INVALID_URL:
        return $this->invalidUrlMessage();

      case ConnectionCheckResult::UNCONFIGURED:
        return $this->uuidResolver->getUuid() === NULL
          ? $this->t('The connection was not tested: the Site UUID is not set or is not a valid UUID.')
          : $this->t('The connection was not tested: no API key is configured.');

      case ConnectionCheckResult::NOT_SENTINEL:
        return $this->t('No Sentinel API answered at @url. Check the API base URL, the network and the TLS certificate, then test again.', ['@url' => $url]);

      case ConnectionCheckResult::UNKNOWN_SITE:
        $arguments = [
          '@sentinel' => $sentinel,
          '@url' => $url,
          ':help' => self::ENVIRONMENTS_HELP_URL,
        ];
        $source = $this->urlResolver->getSource();
        if ($source === ApiBaseUrlResolver::SOURCE_SETTINGS) {
          return $this->t('@sentinel does not know this Site UUID. This environment sends to @url (set in settings.php). Check that the UUID and API key belong to a site in @sentinel, or remove the override to use production. <a href=":help">Use several environments</a>', $arguments);
        }
        if ($source === ApiBaseUrlResolver::SOURCE_ENVIRONMENT) {
          return $this->t('@sentinel does not know this Site UUID. This environment sends to @url (set in the @env environment variable). Check that the UUID and API key belong to a site in @sentinel, or remove the override to use production. <a href=":help">Use several environments</a>', $arguments + ['@env' => ApiBaseUrlResolver::ENV_VAR]);
        }
        return $this->t('@sentinel does not know this Site UUID. This environment sends to @url, the default. Check that the UUID and API key belong to a site in @sentinel. <a href=":help">Use several environments</a>', $arguments);

      case ConnectionCheckResult::KEY_REFUSED:
        return $this->t('@sentinel refused the API key, or the key belongs to another site. Copy the API key of this site from Sentinel and store it again.', ['@sentinel' => $sentinel]);

      case ConnectionCheckResult::LIMITED:
        $refusal = $result->refusal;
        if ($refusal !== NULL && $refusal->isSubscriptionInactive()) {
          return $this->pushLimitFormatter->subscriptionError($refusal->reason, $refusal->message);
        }
        if ($refusal !== NULL) {
          return $this->pushLimitFormatter->warning($refusal);
        }
        return $this->t('Sentinel refused the check over a plan limit.');

      default:
        return $this->t('The connection check failed: @message', ['@message' => $result->refusal->message ?? $this->t('Sentinel refused the check.')]);
    }
  }

  /**
   * Says why the API base URL in use is refused, and where it is set.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message, or one that says the URL is valid.
   */
  public function invalidUrlMessage(): TranslatableMarkup {
    $error = $this->urlResolver->getError();
    if ($error === NULL) {
      return $this->t('The Sentinel API base URL is valid.');
    }
    $arguments = [
      '@value' => $this->urlResolver->getDisplayValue() ?? $this->t('a value with a user name or password, not shown'),
      '@reason' => $error,
      '@env' => ApiBaseUrlResolver::ENV_VAR,
      '@settings' => "\$settings['" . ApiBaseUrlResolver::SETTINGS_KEY . "']",
    ];
    if ($this->urlResolver->getLegacyHost() !== NULL) {
      return $this->t('The Sentinel API base URL is not set for this environment. @reason Nothing is sent until this is fixed.', $arguments);
    }
    return $this->urlResolver->getSource() === ApiBaseUrlResolver::SOURCE_ENVIRONMENT
      ? $this->t('The Sentinel API base URL is not valid. The @env environment variable is set to @value. @reason Nothing is sent until this is fixed.', $arguments)
      : $this->t('The Sentinel API base URL is not valid. @settings in settings.php is set to @value. @reason Nothing is sent until this is fixed.', $arguments);
  }

}
