# Security policy

## Scope

This policy covers the Sentinel Connector Drupal module (`sentinel_connector`)
and its bundled submodules. The separately hosted Sentinel Connector service
publishes its security information at
[SentinelConnector.io/security](https://sentinelconnector.io/security).

## Reporting a vulnerability

### This Drupal module

Sentinel Connector is not currently covered by the
[Drupal Security Advisory policy](https://www.drupal.org/drupal-security-team/security-advisory-process-and-permissions-policy).
Drupal's [security reporting guidance](https://www.drupal.org/docs/develop/issues/issue-procedures-and-etiquette/reporting-a-security-issue)
instructs reporters to use the **Report a security vulnerability** link on a
project's Drupal.org page even when the project is not covered by the policy.
For Sentinel Connector, use the link on the
[project page](https://www.drupal.org/project/sentinel_connector). It creates a
confidential issue in the project queue and alerts the Drupal Security Team.
If you cannot use the confidential tracker, email
[security@drupal.org](mailto:security@drupal.org); in most cases, the team will
ask you to use the tracker. Do not post vulnerability details in the public
issue queue, merge requests, forums or comments while they are being assessed.
Confidential reporting does not itself add advisory coverage; that status is
determined separately.

### The hosted Sentinel service

The separately hosted Sentinel service has its own reporting process. Its
source-controlled security page and `.well-known/security.txt` list the private
reporting contact **support@sentinelconnector.io** with the subject **Security report**.
Live publication of these details has not been verified. Do not include real
customer data. A report does not grant permission to test the hosted service.

For a module report, please include:

- The affected component and installed module, Drupal and PHP versions.
- A description of the issue and the access or configuration it requires.
- Reproduction steps or a minimal proof of concept using synthetic data.
- The expected and actual behaviour, and the potential security impact.
- A way to contact you for clarification.

Do not include customer data, passwords, API keys, access tokens or other
secrets. If sensitive evidence is necessary, describe it first and ask how to
share it safely.

Please keep exploit details private while the report is assessed and any
necessary disclosure is coordinated. This policy does not promise a response or
remediation within a fixed time, or offer a bug bounty.

## Version information

Use the [Drupal.org project page](https://www.drupal.org/project/sentinel_connector)
for published releases and their status. Please report the exact release or
commit affected and, where safe in an environment you control, whether the issue
also affects the latest published release.

The existence of a release, branch or compatibility declaration is not a promise
of ongoing security support for that version.

## Safe testing

A vulnerability report does not grant permission to test the hosted service,
customer sites or other systems. Reproduce issues only in environments you own
or are explicitly authorised to test. Do not access other users' data, interrupt
services, or retain or disclose sensitive information encountered accidentally.

## Other issues

For ordinary bugs or support questions, use the project issue queue or the
[Sentinel Connector website](https://sentinelconnector.io/). For vulnerabilities
in Drupal core or another contributed project, follow
[Drupal's vulnerability-reporting process](https://www.drupal.org/docs/develop/issues/issue-procedures-and-etiquette/reporting-a-security-issue).
