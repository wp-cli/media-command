Feature: Prune WordPress attachment thumbnails

  Background:
    Given a WP install
    And I try `wp theme install twentynineteen --activate`

  Scenario: Prune all images while none exists
    When I try `wp media prune --yes`
    Then STDERR should contain:
      """
      No images found.
      """
    And the return code should be 0

  @require-wp-5.3
  Scenario: Prune all thumbnails for all images
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |
      | {CACHE_DIR}/canola.jpg      | http://wp-cli.org/behat-data/canola.jpg      |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --title="My large attachment" --porcelain`
    Then save STDOUT as {LARGE_ATTACHMENT_ID}
    And the wp-content/uploads/large-image.jpg file should exist
    And the wp-content/uploads/large-image-scaled.jpg file should exist
    And the wp-content/uploads/large-image-150x150.jpg file should exist
    And the wp-content/uploads/large-image-300x225.jpg file should exist

    When I run `wp media import {CACHE_DIR}/canola.jpg --title="My medium attachment" --porcelain`
    Then save STDOUT as {MEDIUM_ATTACHMENT_ID}
    And the wp-content/uploads/canola.jpg file should exist
    And the wp-content/uploads/canola-150x150.jpg file should exist
    And the wp-content/uploads/canola-300x225.jpg file should exist

    When I run `wp media prune --yes`
    Then STDOUT should contain:
      """
      Found 2 images to prune.
      """
    And STDOUT should contain:
      """
      /2 Pruned thumbnails for "My large attachment" (ID {LARGE_ATTACHMENT_ID})
      """
    And STDOUT should contain:
      """
      /2 Pruned thumbnails for "My medium attachment" (ID {MEDIUM_ATTACHMENT_ID})
      """
    And STDOUT should contain:
      """
      Success: Pruned 2 of 2 images.
      """
    And the wp-content/uploads/large-image.jpg file should exist
    And the wp-content/uploads/large-image-scaled.jpg file should exist
    And the wp-content/uploads/large-image-150x150.jpg file should not exist
    And the wp-content/uploads/large-image-300x225.jpg file should not exist
    And the wp-content/uploads/canola.jpg file should exist
    And the wp-content/uploads/canola-150x150.jpg file should not exist
    And the wp-content/uploads/canola-300x225.jpg file should not exist

    When I run `wp post meta get {LARGE_ATTACHMENT_ID} _wp_attachment_metadata --format=json`
    Then STDOUT should not contain:
      """
      "thumbnail"
      """
    And STDOUT should not contain:
      """
      "medium"
      """

  @require-wp-5.3
  Scenario: Prune a specific image size
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --title="My large attachment" --porcelain`
    Then save STDOUT as {LARGE_ATTACHMENT_ID}
    And the wp-content/uploads/large-image-150x150.jpg file should exist
    And the wp-content/uploads/large-image-300x225.jpg file should exist

    When I run `wp media prune --image_size=thumbnail {LARGE_ATTACHMENT_ID}`
    Then STDOUT should contain:
      """
      Pruned thumbnails for "My large attachment" (ID {LARGE_ATTACHMENT_ID})
      """
    And STDOUT should contain:
      """
      Success: Pruned 1 of 1 images.
      """
    And the wp-content/uploads/large-image-150x150.jpg file should not exist
    And the wp-content/uploads/large-image-300x225.jpg file should exist

  @require-wp-5.3
  Scenario: Prune does not remove abandoned (unregistered) thumbnails by default
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |
    And a wp-content/mu-plugins/media-settings.php file:
      """
      <?php
      add_action( 'after_setup_theme', function(){
        add_image_size( 'abandoned_size', 200, 200, true );
      });
      """
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --title="My large attachment" --porcelain`
    Then save STDOUT as {LARGE_ATTACHMENT_ID}
    And the wp-content/uploads/large-image-200x200.jpg file should exist

    # Remove the custom image size (simulating an abandoned size).
    Given a wp-content/mu-plugins/media-settings.php file:
      """
      <?php
      """

    When I run `wp media prune --yes`
    Then STDOUT should contain:
      """
      Success: Pruned
      """
    And the wp-content/uploads/large-image-200x200.jpg file should exist

    When I run `wp post meta get {LARGE_ATTACHMENT_ID} _wp_attachment_metadata --format=json`
    Then STDOUT should contain:
      """
      "abandoned_size"
      """

  @require-wp-5.3
  Scenario: Prune removes abandoned thumbnails with --remove-abandoned
    Given download:
      | path                        | url                                          |
      | {CACHE_DIR}/large-image.jpg | http://wp-cli.org/behat-data/large-image.jpg |
    And a wp-content/mu-plugins/media-settings.php file:
      """
      <?php
      add_action( 'after_setup_theme', function(){
        add_image_size( 'abandoned_size', 200, 200, true );
      });
      """
    And I run `wp option update uploads_use_yearmonth_folders 0`

    When I run `wp media import {CACHE_DIR}/large-image.jpg --title="My large attachment" --porcelain`
    Then save STDOUT as {LARGE_ATTACHMENT_ID}
    And the wp-content/uploads/large-image-200x200.jpg file should exist

    # Remove the custom image size (simulating an abandoned size).
    Given a wp-content/mu-plugins/media-settings.php file:
      """
      <?php
      """

    When I run `wp media prune --remove-abandoned --yes`
    Then STDOUT should contain:
      """
      Success: Pruned
      """
    And the wp-content/uploads/large-image-200x200.jpg file should not exist

    When I run `wp post meta get {LARGE_ATTACHMENT_ID} _wp_attachment_metadata --format=json`
    Then STDOUT should not contain:
      """
      "abandoned_size"
      """

  Scenario: Error on unknown image size
    When I try `wp media prune --image_size=nonexistent --yes`
    Then STDERR should contain:
      """
      Unknown image size "nonexistent".
      """
    And the return code should be 1
