Feature: Find orphan WordPress media

  Background:
    Given a WP install
    And I run `wp option update uploads_use_yearmonth_folders 0`

  Scenario: Report no orphans when the library is consistent
    Given download:
      | path                   | url                                           |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |

    When I run `wp media import {CACHE_DIR}/canola.jpg --title="A clean attachment" --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}
    And the wp-content/uploads/canola.jpg file should exist

    When I run `wp media find-orphans --type=database`
    Then STDOUT should contain:
      """
      Success: No orphan media found.
      """
    And the return code should be 0

  Scenario: Detect a file on disk that is not in the media library
    Given download:
      | path                   | url                                           |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |
    And a wp-content/uploads/stray-image.jpg file:
      """
      not a real image, just a stray file on disk
      """

    When I run `wp media find-orphans --type=filesystem`
    Then STDOUT should contain:
      """
      filesystem
      """
    And STDOUT should contain:
      """
      stray-image.jpg
      """
    And STDOUT should contain:
      """
      File on disk not in media library
      """
    And the return code should be 0

  @require-wp-5.3
  Scenario: Detect an attachment whose file is missing from disk
    Given download:
      | path                   | url                                           |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |

    When I run `wp media import {CACHE_DIR}/canola.jpg --title="My medium attachment" --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}
    And the wp-content/uploads/canola.jpg file should exist

    # Remove the original file on disk while keeping the database record.
    When I run `rm wp-content/uploads/canola.jpg`
    Then the wp-content/uploads/canola.jpg file should not exist

    When I run `wp media find-orphans --type=database`
    Then STDOUT should contain:
      """
      database
      """
    And STDOUT should contain:
      """
      {ATTACHMENT_ID}
      """
    And STDOUT should contain:
      """
      canola.jpg
      """
    And STDOUT should contain:
      """
      Attachment file missing from disk
      """
    And the return code should be 0

  Scenario: Detect a thumbnail whose parent attachment is gone
    # A thumbnail-named file on disk whose parent original/attachment does not
    # exist. (Note: `wp post delete --force` also removes the thumbnail files,
    # so we stage the orphan thumbnail directly.)
    Given a wp-content/uploads/orphan-image-150x150.jpg file:
      """
      not a real image, just a stray thumbnail on disk
      """

    When I run `wp media find-orphans --type=thumbnails`
    Then STDOUT should contain:
      """
      thumbnails
      """
    And STDOUT should contain:
      """
      orphan-image-150x150.jpg
      """
    And STDOUT should contain:
      """
      Thumbnail parent attachment missing
      """
    And the return code should be 0

  Scenario: Detect an unreferenced attachment but not a featured image
    Given download:
      | path                        | url                                                |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.github.io/behat-data/canola.jpg      |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.github.io/behat-data/large-image.jpg |

    When I run `wp post create --post_type=post --post_title="A post" --post_status=publish --porcelain`
    Then save STDOUT as {POST_ID}

    When I run `wp media import {CACHE_DIR}/canola.jpg --title="Unused attachment" --porcelain`
    Then save STDOUT as {UNUSED_ATTACHMENT_ID}

    When I run `wp media import {CACHE_DIR}/large-image.jpg --post_id={POST_ID} --featured_image --title="Featured attachment" --porcelain`
    Then save STDOUT as {FEATURED_ATTACHMENT_ID}

    # Attached to the post via post_parent only: no featured image, no content
    # reference. Must still count as used.
    When I run `wp media import {CACHE_DIR}/canola.jpg --post_id={POST_ID} --title="Attached attachment" --porcelain`
    Then save STDOUT as {ATTACHED_ATTACHMENT_ID}

    When I run `wp media find-orphans --type=usage`
    Then STDOUT should contain:
      """
      usage
      """
    And STDOUT should contain:
      """
      {UNUSED_ATTACHMENT_ID}
      """
    And STDOUT should contain:
      """
      Attachment appears unused in content
      """
    And STDOUT should not contain:
      """
      {FEATURED_ATTACHMENT_ID}
      """
    And STDOUT should not contain:
      """
      {ATTACHED_ATTACHMENT_ID}
      """
    And the return code should be 0

  Scenario: Output orphans as valid JSON
    Given download:
      | path                   | url                                           |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |

    When I run `wp media import {CACHE_DIR}/canola.jpg --title="My medium attachment" --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}
    And the wp-content/uploads/canola.jpg file should exist

    # Remove the original file on disk to create a deterministic database orphan.
    When I run `rm wp-content/uploads/canola.jpg`
    Then the wp-content/uploads/canola.jpg file should not exist

    When I run `wp media find-orphans --type=database --format=json`
    Then STDOUT should be JSON containing:
      """
      [
        {
          "type": "database",
          "attachment_id": "{ATTACHMENT_ID}",
          "file": "canola.jpg",
          "issue": "Attachment file missing from disk",
          "path": "canola.jpg"
        }
      ]
      """
    And the return code should be 0

  Scenario: Return a non-zero exit code with --error-on-orphans
    Given a wp-content/uploads/stray-image.jpg file:
      """
      not a real image, just a stray file on disk
      """

    When I try `wp media find-orphans --type=filesystem --error-on-orphans`
    Then STDOUT should contain:
      """
      stray-image.jpg
      """
    And the return code should be 1

  Scenario: Run every detector when no type is given
    Given download:
      | path                   | url                                           |
      | {CACHE_DIR}/canola.jpg | http://wp-cli.github.io/behat-data/canola.jpg |

    When I run `wp media import {CACHE_DIR}/canola.jpg --title="A clean attachment" --porcelain`
    Then save STDOUT as {ATTACHMENT_ID}

    Given a wp-content/uploads/stray-image.jpg file:
      """
      not a real image, just a stray file on disk
      """

    # No --type runs filesystem + database + thumbnails + usage together.
    When I run `wp media find-orphans`
    Then STDOUT should contain:
      """
      filesystem
      """
    And STDOUT should contain:
      """
      stray-image.jpg
      """
    And STDOUT should contain:
      """
      usage
      """
    And the return code should be 0

  Scenario: Reject an invalid --type value
    When I try `wp media find-orphans --type=bogus`
    Then STDERR should contain:
      """
      Invalid value specified for 'type'
      """
    And the return code should be 1

  Scenario: Reject an invalid --limit value
    When I try `wp media find-orphans --limit=-5`
    Then STDERR should contain:
      """
      The --limit value must be an integer
      """
    And the return code should be 1

  Scenario: Cap the number of results with --limit
    Given a wp-content/uploads/stray-one.jpg file:
      """
      stray file one
      """
    And a wp-content/uploads/stray-two.jpg file:
      """
      stray file two
      """

    When I run `wp media find-orphans --type=filesystem --format=count`
    Then STDOUT should be:
      """
      2
      """

    When I run `wp media find-orphans --type=filesystem --limit=1 --format=count`
    Then STDOUT should be:
      """
      1
      """
    And the return code should be 0

  Scenario: Restrict output columns with --fields
    Given a wp-content/uploads/stray-image.jpg file:
      """
      not a real image, just a stray file on disk
      """

    When I run `wp media find-orphans --type=filesystem --fields=type,file --format=csv`
    Then STDOUT should contain:
      """
      type,file
      """
    And STDOUT should contain:
      """
      filesystem,stray-image.jpg
      """
    And the return code should be 0

  Scenario: Include orphan thumbnails in the filesystem scan with --include-thumbnails
    Given a wp-content/uploads/lonely-image-400x300.jpg file:
      """
      not a real image, just a stray thumbnail-named file
      """

    # Thumbnail-named files are skipped by the filesystem scan by default.
    When I run `wp media find-orphans --type=filesystem`
    Then STDOUT should not contain:
      """
      lonely-image-400x300.jpg
      """

    # With the flag, the same file is reported as a filesystem orphan.
    When I run `wp media find-orphans --type=filesystem --include-thumbnails`
    Then STDOUT should contain:
      """
      lonely-image-400x300.jpg
      """
    And STDOUT should contain:
      """
      File on disk not in media library
      """
    And the return code should be 0

  Scenario: Skip generated subdirectories during the filesystem scan
    Given a wp-content/uploads/cache/generated-image.jpg file:
      """
      a plugin-generated cache file that must be ignored
      """

    When I run `wp media find-orphans --type=filesystem`
    Then STDOUT should contain:
      """
      Success: No orphan media found.
      """
    And STDOUT should not contain:
      """
      generated-image.jpg
      """
    And the return code should be 0
