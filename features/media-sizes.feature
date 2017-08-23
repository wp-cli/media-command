Feature: List media sizes

  Scenario: Basic usage
    Given a WP install

    When I run `wp media sizes`
    Then STDOUT should be a table containing rows:
      | name       | width     | height    | crop   |
      | full       |           |           | false  |
      | large      | 1024      | 1024      | true   |
