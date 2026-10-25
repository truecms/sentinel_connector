<?php

namespace Drupal\upgrade_status;

use Drupal\Core\State\StateInterface;

/**
 * Returns projects shaped as Upgrade Status 4.3.x returns them.
 *
 * Each project is an object with an info array holding upgrade_status_type
 * and upgrade_status_next, keyed by project machine name.
 */
class ProjectCollector {

  /**
   * State key: next step and type per project name.
   */
  public const STATE_PROJECTS = 'upgrade_status_test.projects';

  /**
   * State key: 'throw' or 'not_array' to break collectProjects().
   */
  public const STATE_MODE = 'upgrade_status_test.mode';

  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * Returns the projects stored in state.
   *
   * @return mixed
   *   Project objects keyed by machine name, or a broken value.
   */
  public function collectProjects(): mixed {
    $mode = $this->state->get(self::STATE_MODE);
    if ($mode === 'throw') {
      throw new \LogicException('Broken project collector.');
    }
    if ($mode === 'not_array') {
      return 'projects';
    }
    $projects = [];
    foreach ($this->state->get(self::STATE_PROJECTS, []) as $name => [$type, $next]) {
      $project = new \stdClass();
      $project->info = [
        'name' => $name,
        'upgrade_status_type' => $type,
        'upgrade_status_next' => $next,
      ];
      $projects[$name] = $project;
    }
    return $projects;
  }

  /**
   * The running core major, as Upgrade Status computes it.
   */
  public static function getDrupalCoreMajorVersion(): int {
    return (int) \Drupal::VERSION;
  }

}
