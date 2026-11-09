@site-access
Feature: Keep existing site access boundaries
  Background:
    Given both networks trust the same identity site

  Rule: Shared identity does not grant a site role
    @SSO-002
    Scenario: A site-local user cannot enter another mapped site
      Given Casey has a role only on Northcoast
      And Casey has a hub session
      When Casey requests single sign-on to Southmountain
      Then Southmountain refuses access

    @SSO-007
    Scenario: Network membership alone does not grant another site
      Given Alex has roles on Northcoast and Southmountain
      And Alex has a hub session
      When Alex requests single sign-on to CoastExtra
      Then CoastExtra refuses access

  Rule: A person can cross networks only into a member site
    @SSO-003
    Scenario: A member of sites in both networks can reach their destination
      Given Riley has roles on Northcoast and Ridgeview
      And Riley has a hub session
      When Riley requests single sign-on to Ridgeview
      Then Riley receives access to Ridgeview

    @SSO-004
    Scenario: A network-local user cannot enter another network's site
      Given Alex has roles on Northcoast and Southmountain
      And Alex has a hub session
      When Alex requests single sign-on to Ridgeview
      Then Ridgeview refuses access

  Rule: Destination membership is checked again when the user returns
    @SSO-008
    Scenario: A removed role prevents completion of an already started sign-in
      Given Riley has roles on Northcoast and Ridgeview
      And Riley has a hub session
      And Riley has started single sign-on to Ridgeview
      When Riley loses their Ridgeview role before returning
      Then Ridgeview refuses the issued sign-in
