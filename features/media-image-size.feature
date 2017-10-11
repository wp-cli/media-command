Feature: List image sizes

  Scenario: Basic usage
    Given a WP install

    When I run `wp media image-size`
    Then STDOUT should be a table containing rows:
      | name       | width     | height    | crop   | hard crop  |
      | full       |           |           | false  | false      |
      | large      | 1024      | 1024      | true   | false      |
    And STDERR should be empty

    When I run `wp media image-size --skip-themes`
    Then STDOUT should be a table containing rows:
      | name       | width     | height    | crop   | hard crop  |
      | full       |           |           | false  | false      |
      | large      | 1024      | 1024      | true   | false      |
    And STDERR should be empty
