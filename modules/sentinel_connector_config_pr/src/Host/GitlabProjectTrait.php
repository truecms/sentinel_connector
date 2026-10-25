<?php

namespace Drupal\sentinel_connector_config_pr\Host;

/**
 * Finds the GitLab project by id or full path instead of a global search.
 *
 * The config_pr controller searches all projects and reads only the first
 * page of results, which can miss a project with a common name on gitlab.com.
 */
trait GitlabProjectTrait {

  /**
   * The project id, or its full path such as group/subgroup/name.
   */
  protected string $projectRef = '';

  /**
   * Sets the project id or full path.
   */
  public function setProjectRef(string $projectRef): void {
    $this->projectRef = $projectRef;
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectDetails(): bool {
    $reference = $this->projectRef !== '' ? $this->projectRef : $this->getRepoOwner() . '/' . $this->getRepoName();
    $project = $this->getClient()->projects()->show($reference);
    if (!is_array($project) || empty($project['id'])) {
      return FALSE;
    }
    $this->repoId = (string) $project['id'];
    $this->defaultBranch = (string) ($project['default_branch'] ?? '');
    return TRUE;
  }

}
