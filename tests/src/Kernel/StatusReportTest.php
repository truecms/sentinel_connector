<?php

namespace Drupal\Tests\sentinel_connector\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sentinel_connector\PushHealth;
use Drupal\sentinel_connector\PushLimitFormatter;
use Drupal\sentinel_connector\SyncService;

/**
 * Kernel tests for the Sentinel Connector entry on the status report.
 *
 * @group sentinel_connector
 */
class StatusReportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'sentinel_connector'];

  /**
   * The time the test started.
   */
  protected int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['sentinel_connector']);
    $this->config('system.date')->set('timezone.default', 'Australia/Sydney')->save();
    $this->config('sentinel_connector.settings')
      ->set('api_base_url', 'https://sentinel.example.com')
      ->set('site_uuid', '11111111-1111-4111-8111-111111111111')
      ->set('site_url', 'https://example.com')
      ->set('site_name', 'Example')
      ->set('enabled', TRUE)
      ->save();
    $this->now = time();
    $this->setState([
      'api_key' => 'sk_test',
      'configured_time' => $this->now - 30 * 86400,
    ]);
    $this->container->get('router.builder')->rebuild();
    require_once $this->root . '/core/includes/install.inc';
    $this->container->get('module_handler')->loadInclude('sentinel_connector', 'install');
  }

  /**
   * The hook reports at runtime only, with core's severity values.
   */
  public function testHookRunsAtRuntimeOnly(): void {
    $this->assertSame([], sentinel_connector_requirements('install'));
    $this->assertSame([], sentinel_connector_requirements('update'));
    $this->assertSame(['sentinel_connector'], array_keys(sentinel_connector_requirements('runtime')));
    foreach (['INFO', 'OK', 'WARNING', 'ERROR'] as $name) {
      $this->assertSame(constant('REQUIREMENT_' . $name), constant(PushHealth::class . '::SEVERITY_' . $name));
    }
  }

  /**
   * Incomplete configuration is an error that links to the settings form.
   *
   * @dataProvider incompleteConfigurations
   */
  public function testIncompleteConfigurationIsAnError(string $missing): void {
    // A recent accepted push must not hide the missing configuration.
    $this->setState(['last_accepted_time' => $this->now - 60]);
    if ($missing === 'api_key') {
      \Drupal::state()->delete('sentinel_connector.api_key');
    }
    else {
      $this->config('sentinel_connector.settings')->set($missing, '')->save();
    }

    $entry = $this->entry();

    $this->assertSame(PushHealth::SEVERITY_ERROR, $entry['severity']);
    $this->assertSame('Not configured', (string) $entry['value']);
    $this->assertStringContainsString('href="/admin/config/services/sentinel-connector"', (string) $entry['description']);
  }

  /**
   * The settings a push cannot do without.
   *
   * @return array<string, array{string}>
   *   The setting that is missing.
   */
  public static function incompleteConfigurations(): array {
    return ['API URL' => ['api_base_url'], 'site UUID' => ['site_uuid'], 'API key' => ['api_key']];
  }

  /**
   * A site configured under a day ago that has not pushed shows a warning.
   */
  public function testNewSiteWithoutPushWarns(): void {
    $this->setState(['configured_time' => $this->now - 3600]);
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_WARNING, $entry['severity']);
    $this->assertSame('No push yet', (string) $entry['value']);
    $this->assertStringContainsString('configured less than 24 hours ago', (string) $entry['description']);
    $this->assertStringContainsString('No push has been attempted.', (string) $entry['description']);

    // A failed first attempt is still a warning on the first day.
    $this->setState([
      'last_attempt_time' => $this->now - 600,
      'last_result' => 'auth_error',
      'last_message' => 'Authentication failed.',
    ]);
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_WARNING, $entry['severity']);
    $this->assertStringContainsString('result: auth_error. Authentication failed.', (string) $entry['description']);

    // With no recorded time the site counts as just configured.
    \Drupal::state()->delete(SyncService::STATE_CONFIGURED_TIME);
    $this->assertSame(PushHealth::SEVERITY_WARNING, $this->entry()['severity']);
  }

  /**
   * No accepted push for a day is an error with the last result and time.
   */
  public function testStaleAcceptedPushIsAnError(): void {
    // Configured long ago and never accepted.
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_ERROR, $entry['severity']);
    $this->assertSame('No push accepted in the last 24 hours', (string) $entry['value']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('Sentinel has not accepted a push from this site yet.', $description);
    $this->assertStringContainsString('No push has been attempted.', $description);
    $this->assertStringContainsString('Run cron and check the <a href="/admin/config/services/sentinel-connector">settings form</a>.', $description);
    $this->assertStringNotContainsString('switched off', $description);

    // Accepted long ago, with a failing last attempt.
    $accepted = $this->now - 86400 - 60;
    $attempt = $this->now - 1800;
    $this->setState([
      'last_accepted_time' => $accepted,
      'last_attempt_time' => $attempt,
      'last_result' => 'transport_error',
      'last_message' => 'Could not connect to <b>Sentinel</b>.',
    ]);
    $this->config('sentinel_connector.settings')->set('enabled', FALSE)->save();
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_ERROR, $entry['severity']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('Last push accepted: ' . $this->siteTime($accepted) . '.', $description);
    $this->assertStringContainsString('Last attempt: ' . $this->siteTime($attempt) . ', result: transport_error.', $description);
    // Stored text is escaped.
    $this->assertStringContainsString('Could not connect to &lt;b&gt;Sentinel&lt;/b&gt;.', $description);
    $this->assertStringContainsString('Automatic push on cron is switched off', $description);
  }

  /**
   * An accepted push under a day old is OK and shows its time.
   */
  public function testRecentAcceptedPushIsOk(): void {
    $accepted = $this->now - 86400 + 120;
    $this->setState(['last_accepted_time' => $accepted, 'last_result' => 'success']);
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_OK, $entry['severity']);
    $this->assertSame('Last push accepted ' . $this->siteTime($accepted), (string) $entry['value']);
    $this->assertSame('', (string) $entry['description']);

    // A later failure does not make a fresh accepted push an error.
    $this->setState(['last_result' => 'server_error', 'last_attempt_time' => $this->now - 60]);
    $this->assertSame(PushHealth::SEVERITY_OK, $this->entry()['severity']);
  }

  /**
   * An old accepted push stays OK while cron finds nothing changed.
   */
  public function testUnchangedInventoryKeepsOldPushOk(): void {
    $accepted = $this->now - 3 * 86400;
    $checked = $this->now - 1800;
    $this->setState([
      'last_accepted_time' => $accepted,
      'last_result' => 'success',
      'last_unchanged_time' => $checked,
    ]);
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_OK, $entry['severity']);
    $this->assertSame('Last push accepted ' . $this->siteTime($accepted), (string) $entry['value']);
    $this->assertStringContainsString('No change since; last checked ' . $this->siteTime($checked) . '.', (string) $entry['description']);

    // Cron stopped checking a day ago: the old push is an error again.
    $this->setState(['last_unchanged_time' => $this->now - 86400 - 60]);
    $this->assertSame(PushHealth::SEVERITY_ERROR, $this->entry()['severity']);

    // A check without any accepted push proves nothing.
    $this->setState(['last_accepted_time' => 0, 'last_unchanged_time' => $checked]);
    $this->assertSame(PushHealth::SEVERITY_ERROR, $this->entry()['severity']);
  }

  /**
   * A push held by the plan limit is information, not an error.
   */
  public function testPushHeldByPlanLimitIsInformation(): void {
    $accepted = $this->now - 3600;
    $next = $this->now + 7200;
    $this->setState([
      'last_accepted_time' => $accepted,
      'last_result' => 'push_limited',
      'next_allowed_at' => $next,
    ]);
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_INFO, $entry['severity']);
    $this->assertSame('Next push held by the plan limit', (string) $entry['value']);
    $description = (string) $entry['description'];
    $this->assertStringContainsString('Last push accepted: ' . $this->siteTime($accepted) . '.', $description);
    $this->assertStringContainsString('Sentinel accepts the next push after ' . $this->siteTime($next) . '.', $description);
  }

  /**
   * A limit that has just ended does not turn into an error before cron runs.
   */
  public function testEndedPlanLimitAllowsCronTimeToPush(): void {
    // The Free plan: the limit ends as the accepted push turns a day old.
    $next = $this->now - 600;
    $this->setState([
      'last_accepted_time' => $this->now - 86400 - 600,
      'last_result' => 'push_limited',
      'next_allowed_at' => $next,
    ]);
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_INFO, $entry['severity']);
    $this->assertStringContainsString('The plan limit ended at ' . $this->siteTime($next) . '. The next cron run sends a push.', (string) $entry['description']);

    // Cron has had long enough: this is now an error.
    $this->setState(['next_allowed_at' => $this->now - PushHealth::CRON_GRACE - 60]);
    $this->assertSame(PushHealth::SEVERITY_ERROR, $this->entry()['severity']);

    // A limit that ends more than a day ahead does not excuse a stale push.
    $this->setState(['next_allowed_at' => $this->now + 2 * 86400]);
    $this->assertSame(PushHealth::SEVERITY_ERROR, $this->entry()['severity']);
  }

  /**
   * A billing refusal is an error with the reason in plain words.
   *
   * @dataProvider subscriptionReasons
   */
  public function testSubscriptionRefusalIsAnError(string $reason, string $label, string $text): void {
    // Even with a recent accepted push and a server message of its own.
    $this->setState([
      'last_accepted_time' => $this->now - 3600,
      'last_attempt_time' => $this->now - 60,
      'last_result' => 'subscription_inactive',
      'last_message' => '',
      'subscription_reason' => $reason,
      'subscription_hold_until' => $this->now + 86000,
    ]);
    $entry = $this->entry();
    $this->assertSame(PushHealth::SEVERITY_ERROR, $entry['severity']);
    $this->assertSame('Push refused: subscription ' . $label, (string) $entry['value']);
    $this->assertStringContainsString($text, (string) $entry['description']);
    $this->assertStringContainsString('Last push accepted:', (string) $entry['description']);

    $this->setState(['last_message' => 'Pay <i>now</i>.']);
    $description = (string) $this->entry()['description'];
    $this->assertStringContainsString('Pay &lt;i&gt;now&lt;/i&gt;.', $description);
    $this->assertStringNotContainsString('<i>', $description);
  }

  /**
   * Reasons, the plain-words label and the fallback text for each.
   *
   * @return array<string, array{string, string, string}>
   *   Stored reason, expected label and expected text.
   */
  public static function subscriptionReasons(): array {
    return [
      'past_due' => ['past_due', 'payment overdue', 'a payment for the subscription is overdue'],
      'unpaid' => ['unpaid', 'unpaid', 'the subscription is unpaid'],
      'paused' => ['paused', 'paused', 'the subscription is paused'],
      'unknown' => ['', 'not active', 'the subscription is not active'],
    ];
  }

  /**
   * Returns the module's status report entry.
   *
   * @return array<string, mixed>
   *   The requirement entry.
   */
  private function entry(): array {
    return sentinel_connector_requirements('runtime')['sentinel_connector'];
  }

  /**
   * Sets module state values by their short names.
   *
   * @param array<string, mixed> $values
   *   Values keyed by the state key without the module prefix.
   */
  private function setState(array $values): void {
    foreach ($values as $key => $value) {
      \Drupal::state()->set('sentinel_connector.' . $key, $value);
    }
  }

  /**
   * Formats a time the way the status report does, in the site time zone.
   */
  private function siteTime(int $timestamp): string {
    $expected = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Australia/Sydney'))->format('j M Y H:i T');
    $this->assertSame($expected, \Drupal::service(PushLimitFormatter::class)->formatTime($timestamp));
    return $expected;
  }

}
