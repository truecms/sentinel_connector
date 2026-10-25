<?php

namespace Drupal\sentinel_connector_config_pr\Host;

use Drupal\config_pr_gitlab\RepoControllers\GitlabSelfManagedController;

/**
 * The config_pr self-managed GitLab controller, finding the project by path.
 */
class GitlabSelfManagedProjectController extends GitlabSelfManagedController {

  use GitlabProjectTrait;

}
