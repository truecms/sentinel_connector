<?php

namespace Drupal\Tests\sentinel_connector_config_pr\Kernel;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\sentinel_connector_config_pr\Form\SettingsForm;
use Drupal\sentinel_connector_config_pr\TokenResolver;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the token sources and that the settings form never shows a token.
 *
 * @group sentinel_connector
 */
#[RunTestsInSeparateProcesses]
#[Group('sentinel_connector')]
class TokenAndFormTest extends KernelTestBase {

  /**
   * A token that must never reach the form.
   */
  protected const TOKEN = 'glpat-form-test-token-0123456789';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'config',
    'key',
    'sentinel_connector',
    'config_pr',
    'sentinel_connector_config_pr',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'sentinel_connector', 'sentinel_connector_config_pr']);
  }

  /**
   * The resolver.
   */
  protected function resolver(): TokenResolver {
    return $this->container->get(TokenResolver::class);
  }

  /**
   * The settings.php token is used; State is never a source.
   */
  public function testSettingsToken(): void {
    $this->container->get('state')->set('sentinel_connector_config_pr', ['token' => 'from_state']);
    $this->assertNull($this->resolver()->getToken());

    $this->setSetting('sentinel_connector_config_pr', ['token' => ' ' . self::TOKEN . ' ']);
    $this->assertSame(self::TOKEN, $this->resolver()->getToken());
    $this->assertSame('settings.php', $this->resolver()->getSource());
  }

  /**
   * A key that reads the environment is used; one kept in config is refused.
   */
  public function testKeyProviders(): void {
    putenv('SENTINEL_CONFIG_PR_TEST_TOKEN=' . self::TOKEN);
    Key::create([
      'id' => 'git_env',
      'label' => 'Git token (environment)',
      'key_type' => 'authentication',
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'SENTINEL_CONFIG_PR_TEST_TOKEN',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
    ])->save();
    Key::create([
      'id' => 'git_config',
      'label' => 'Git token (configuration)',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'from_config'],
    ])->save();
    // settings.php also has a token: a configured key wins, even when broken.
    $this->setSetting('sentinel_connector_config_pr', ['token' => 'from_settings']);
    $settings = $this->config('sentinel_connector_config_pr.settings');

    $settings->set('token_key_id', 'git_env')->save();
    $this->assertSame(self::TOKEN, $this->resolver()->getToken());
    $this->assertSame('key', $this->resolver()->getSource());

    $settings->set('token_key_id', 'git_config')->save();
    $this->assertNull($this->resolver()->getToken());
    $this->assertSame('config_provider', $this->resolver()->getKeyProblem());

    $settings->set('token_key_id', 'missing')->save();
    $this->assertNull($this->resolver()->getToken());
    $this->assertSame('missing', $this->resolver()->getKeyProblem());
    putenv('SENTINEL_CONFIG_PR_TEST_TOKEN');
  }

  /**
   * The form says a token is present and never contains it.
   */
  public function testFormShowsOnlyTokenPresence(): void {
    $form = $this->buildForm();
    $this->assertStringContainsString('Not configured', (string) $form['token']['token_status']['#markup']);

    $this->setSetting('sentinel_connector_config_pr', ['token' => self::TOKEN]);
    $form = $this->buildForm();
    $this->assertStringContainsString('Present, from settings.php', (string) $form['token']['token_status']['#markup']);
    $this->assertSame('key_select', $form['token']['token_key_id']['#type']);
    $this->assertStringNotContainsString(self::TOKEN, serialize($this->flatten($form)));

    // No form element and no configuration key holds a token value.
    $this->assertArrayNotHasKey('token', $this->config('sentinel_connector_config_pr.settings')->getRawData());
  }

  /**
   * Builds the settings form.
   *
   * @return array<string, mixed>
   *   The form.
   */
  protected function buildForm(): array {
    $this->container->get('router.builder')->rebuild();
    $formState = new FormState();
    return $this->container->get('form_builder')->buildForm(SettingsForm::class, $formState);
  }

  /**
   * The scalar values of a form, for searching.
   *
   * @param array<mixed> $form
   *   The form.
   *
   * @return string[]
   *   The values.
   */
  protected function flatten(array $form): array {
    $values = [];
    array_walk_recursive($form, static function ($value) use (&$values): void {
      if (is_scalar($value) || $value instanceof \Stringable) {
        $values[] = (string) $value;
      }
    });
    return $values;
  }

}
