<?php

namespace Drupal\sentinel_connector_config_pr\Host;

use Drupal\config_pr_gitlab\RepoControllers\GitLabController;

/**
 * The config_pr GitLab.com controller, finding the project by id or path.
 */
class GitlabProjectController extends GitLabController {

  use GitlabProjectTrait;

}
