Feature: List image sizes

  @require-wp-4.8
  Scenario: Basic usage
    Given a WP install

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
