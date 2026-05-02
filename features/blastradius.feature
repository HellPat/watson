Feature: blastradius reports affected entry points for a diff
  As a developer running `watson:blastradius` against a real PR
  I want to see exactly which routes / commands the diff affects
  So that an AI reviewer or human can focus their review

  Scenario: Symfony controller edit lights up its routes
    Given the Symfony fixture
    And the working tree starts clean from "src/Controller/HelloController.php"
    And I edit "src/Controller/HelloController.php"
    When I run "watson:blastradius" against the working-tree diff via bin/console
    Then the JSON output reports at least 2 affected entry points
    And the affected entry points are all of kind "symfony.route"

  Scenario: Laravel controller edit lights up its routes
    Given the Laravel fixture
    And the working tree starts clean from "app/Http/Controllers/HelloController.php"
    And I edit "app/Http/Controllers/HelloController.php"
    When I run "watson:blastradius" against the working-tree diff via artisan
    Then the JSON output reports at least 2 affected entry points
    And the affected entry points are all of kind "laravel.route"
