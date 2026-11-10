@mapped-domains
Feature: Reuse a completed local login
  Rule: A completed login can be reused on another member site
    @SSO-001
    Scenario: Move from Northcoast to Southmountain without authenticating again
      Given Alex has roles on Northcoast and Southmountain
      And Alex completed local sign-in on Northcoast
      When Alex requests single sign-on to Southmountain
      Then Alex receives access to Southmountain

  Rule: An unfinished login cannot establish shared identity
    @SSO-005
    Scenario: A pending second factor cannot establish the hub session
      Given Alex has roles on Northcoast and Southmountain
      And Alex has not completed the second factor on Northcoast
      When Alex tries to establish the hub session
      Then the hub session is refused

  Rule: Another person's hub identity is never replaced silently
    @SSO-006
    Scenario: Keep an existing hub account when another user signs in locally
      Given Alex has roles on Northcoast and Southmountain
      And Morgan already has a hub session
      When Alex completes local sign-in on Northcoast
      Then the hub session is refused
      And Morgan keeps the hub session

  Rule: A site-local account never leaves its site
    @SSO-009
    Scenario: A one-site member signs in without visiting the identity site
      Given Casey has a role only on Northcoast
      When Casey signs in locally on Northcoast
      Then Casey stays on Northcoast
