Feature: list-entrypoints command snapshots the framework's runtime routes
  As a developer running `watson:list-entrypoints` against a real app
  I want every route registered at runtime to appear in the JSON output
  So that I can confirm watson sees the same surface my framework exposes

  Scenario: Symfony fixture exposes its routes via the runtime registry
    Given the Symfony fixture
    When I run "watson:list-entrypoints" via bin/console
    Then the JSON output contains at least 2 entry points
    And every entry point should be tagged source = "runtime"
    And the framework should be reported as "symfony"

  Scenario: Laravel fixture exposes its routes via the runtime registry
    Given the Laravel fixture
    When I run "watson:list-entrypoints" via artisan
    Then the JSON output contains at least 2 entry points
    And every "laravel.route" entry point should be tagged source = "runtime"
    And the framework should be reported as "laravel"
