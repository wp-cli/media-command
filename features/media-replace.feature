Feature: Replace WordPress attachment files

  Background:
    Given a WP install

  Scenario: Replace an attachment file with a local file
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.org/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDOUT should contain:
      """
      Replaced file for attachment ID {ATTACHMENT_ID}
      """
    And STDOUT should contain:
      """
      Success: Replaced 1 of 1 images.
      """

  Scenario: Replace an attachment file from a URL
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp media replace {ATTACHMENT_ID} 'http://wp-cli.org/behat-data/canola.jpg'`
    Then STDOUT should contain:
      """
      Replaced file for attachment ID {ATTACHMENT_ID}
      """
    And STDOUT should contain:
      """
      Success: Replaced 1 of 1 images.
      """

  Scenario: Replace an attachment file and output only the attachment ID in porcelain mode
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.org/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg --porcelain`
    Then STDOUT should be:
      """
      {ATTACHMENT_ID}
      """

  Scenario: Preserve attachment metadata after replacing the file
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.org/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --title="My Image Title" --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I run `wp media replace {ATTACHMENT_ID} {CACHE_DIR}/canola.jpg`
    Then STDOUT should contain:
      """
      Success: Replaced 1 of 1 images.
      """

    When I run `wp post get {ATTACHMENT_ID} --field=post_title`
    Then STDOUT should be:
      """
      My Image Title
      """

  Scenario: Error when replacing with a non-existent local file
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |

    When I run `wp media import {CACHE_DIR}/large-image.jpg --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    When I try `wp media replace {ATTACHMENT_ID} /tmp/nonexistent-file.jpg`
    Then STDERR should contain:
      """
      Error: Unable to replace attachment
      """
    And STDERR should contain:
      """
      File doesn't exist.
      """
    And the return code should be 1

  Scenario: Error when replacing with an invalid attachment ID
    When I try `wp media replace 999999 /tmp/fake.jpg`
    Then STDERR should contain:
      """
      Error: Invalid attachment ID 999999.
      """
    And the return code should be 1
