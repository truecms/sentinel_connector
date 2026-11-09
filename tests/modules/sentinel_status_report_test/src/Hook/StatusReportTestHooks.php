<?php

namespace Drupal\sentinel_status_report_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Returns the requirements a test stores in state.
 *
 * State keys:
 * - sentinel_status_report_test.items: the requirements to return.
 * - sentinel_status_report_test.throw: TRUE to throw instead.
 * - sentinel_status_report_test.advance: seconds the test clock moves on
 *   each call, to go over the time budget.
 * - sentinel_status_report_test.alter: requirements the alter hook adds or
 *   replaces.
 * - sentinel_status_report_test.enum: TRUE to return Drupal 11.2 enum
 *   severities where the enum exists.
 * - sentinel_status_report_test.account: TRUE to add a warning that names
 *   the current user's id.
 */
class StatusReportTestHooks {

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $state = \Drupal::state();
    $state->set('sentinel_status_report_test.calls', (int) $state->get('sentinel_status_report_test.calls', 0) + 1);
    if ($state->get('sentinel_status_report_test.throw')) {
      throw new \RuntimeException('Test requirement failure');
    }
    $advance = (int) $state->get('sentinel_status_report_test.advance', 0);
    $time = \Drupal::time();
    if ($advance > 0 && method_exists($time, 'advance')) {
      $time->advance($advance);
    }
    $items = $state->get('sentinel_status_report_test.items', []);
    if ($state->get('sentinel_status_report_test.account')) {
      $items['account_item'] = [
        'title' => 'Account',
        'value' => 'uid ' . \Drupal::currentUser()->id(),
        'severity' => 1,
      ];
    }
    $enum = 'Drupal\Core\Extension\Requirement\RequirementSeverity';
    if ($state->get('sentinel_status_report_test.enum') && enum_exists($enum)) {
      foreach ($items as &$item) {
        if (is_int($item['severity'] ?? NULL)) {
          $item['severity'] = $enum::from($item['severity']);
        }
      }
    }
    return $items;
  }

  /**
   * Implements hook_runtime_requirements_alter().
   *
   * @param array<string, array<string, mixed>> $requirements
   *   The requirements.
   */
  #[Hook('runtime_requirements_alter')]
  public function runtimeRequirementsAlter(array &$requirements): void {
    /** @var array<string, array<string, mixed>> $items */
    $items = \Drupal::state()->get('sentinel_status_report_test.alter', []);
    foreach ($items as $key => $item) {
      $requirements[$key] = $item;
    }
  }

}
