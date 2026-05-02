@smoke
Feature: real-app smoke against ~/easy-plu/backend
  As a watson maintainer validating against a real Laravel codebase
  I want to confirm watson:list-entrypoints returns sensible counts
  So that we catch regressions on real-world surface, not just hermetic fixtures

  Run with: WATSON_EASY_PLU_ROOT=~/easy-plu/backend vendor/bin/behat --tags=smoke

  Scenario: easy-plu list-entrypoints surfaces routes and commands
    Given the easy-plu Laravel app at WATSON_EASY_PLU_ROOT
    When I run "watson:list-entrypoints --scope=routes" via artisan
    Then the JSON output contains entry points of kind "laravel.route"
    And the framework should be reported as "laravel"
