Feature: List image sizes

  @require-wp-5.3
  Scenario: Basic usage
    Given a WP install
    # Differing themes can have differing default image sizes. Let's stick to one.
    And I try `wp theme install twentynineteen --activate`

    When I run `wp media image-size`
    Then STDOUT should be a table containing rows:
      | name           | width     | height    | crop   | ratio |
      | full           |           |           | N/A    | N/A   |
      | 2048x2048      | 2048      | 2048      | soft   | N/A   |
      | post-thumbnail | 1568      | 9999      | soft   | N/A   |
      | large          | 1024      | 1024      | soft   | N/A   |
      | medium_large   | 768       | 0         | soft   | N/A   |
      | medium         | 300       | 300       | soft   | N/A   |
      | thumbnail      | 150       | 150       | hard   | 1:1   |
    And STDERR should be empty

    When I run `wp media image-size --skip-themes`
    Then STDOUT should be a table containing rows:
      | name           | width     | height    | crop   | ratio |
      | full           |           |           | N/A    | N/A   |
      | large          | 1024      | 1024      | soft   | N/A   |
      | medium_large   | 768       | 0         | soft   | N/A   |
      | medium         | 300       | 300       | soft   | N/A   |
      | thumbnail      | 150       | 150       | hard   | 1:1   |
    And STDERR should be empty

  @require-wp-5.3
  Scenario: Basic usage with plugin which registers custom image size
    Given a WP install
    # Differing themes can have differing default image sizes. Let's stick to one.
    And I try `wp theme install twentynineteen --activate`
    And a wp-content/plugins/foo-bar.php file:
      """
      <?php
      /**
        * Plugin Name: Foo Bar
        * Plugin URI: https://example.com
        * Description: Custom plugin.
        * Version: 0.1.0
        * Author: John Doe
        * Author URI: https://johndoe.com/
        * License: GPL-2.0+
        * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
        */

      add_action(
        'after_setup_theme',
        function () {
          add_image_size( 'foo-bar-thumb', 50, 50, true );
        }
      );
      """
    And I try `wp plugin activate foo-bar.php`

    When I run `wp media image-size`
    Then STDOUT should be a table containing rows:
      | name           | width     | height    | crop   | ratio |
      | full           |           |           | N/A    | N/A   |
      | 2048x2048      | 2048      | 2048      | soft   | N/A   |
      | post-thumbnail | 1568      | 9999      | soft   | N/A   |
      | large          | 1024      | 1024      | soft   | N/A   |
      | medium_large   | 768       | 0         | soft   | N/A   |
      | medium         | 300       | 300       | soft   | N/A   |
      | thumbnail      | 150       | 150       | hard   | 1:1   |
      | foo-bar-thumb  | 50        | 50        | hard   | 1:1   |
    And STDERR should be empty

    When I run `wp media image-size --format=csv`
    Then STDOUT should not contain:
      """
      ,0,0,
      """

  # Behavior changed with WordPress 5.3+, so we're adding separate tests for previous versions.
  # Change that impacts this:
  # https://core.trac.wordpress.org/ticket/43524
  @require-wp-4.8 @less-than-wp-5.3
  Scenario: Basic usage (pre-WP-5.3)
    Given a WP install
    # Differing themes can have differing default image sizes. Let's stick to one.
    And I try `wp theme install twentynineteen --activate`

    When I run `wp media image-size`
    Then STDOUT should be a table containing rows:
      | name           | width     | height    | crop   | ratio |
      | full           |           |           | N/A    | N/A   |
      | post-thumbnail | 1568      | 9999      | soft   | N/A   |
      | large          | 1024      | 1024      | soft   | N/A   |
      | medium_large   | 768       | 0         | soft   | N/A   |
      | medium         | 300       | 300       | soft   | N/A   |
      | thumbnail      | 150       | 150       | hard   | 1:1   |
    And STDERR should be empty

    When I run `wp media image-size --skip-themes`
    Then STDOUT should be a table containing rows:
      | name           | width     | height    | crop   | ratio |
      | full           |           |           | N/A    | N/A   |
      | large          | 1024      | 1024      | soft   | N/A   |
      | medium_large   | 768       | 0         | soft   | N/A   |
      | medium         | 300       | 300       | soft   | N/A   |
      | thumbnail      | 150       | 150       | hard   | 1:1   |
    And STDERR should be empty
