@external-sign-in
Feature: Sign in through OneLogin
  Rule: External identity remains subject to destination membership
    @SAML-001
    Scenario: An existing member enters their site through OneLogin
      Given Riley has roles on Northcoast and Ridgeview
      And OneLogin is configured without automatic provisioning
      When Riley requests OneLogin sign-in to Ridgeview
      Then Riley receives access to Ridgeview

    @SAML-002
    Scenario: External authentication does not grant another site role
      Given Casey has a role only on Northcoast
      And OneLogin is configured without automatic provisioning
      When Casey requests OneLogin sign-in to Southmountain
      Then Southmountain refuses access

    @SAML-003
    Scenario: An unknown external account is not created automatically
      Given OneLogin is configured without automatic provisioning
      When Unknown requests OneLogin sign-in to Southmountain
      Then the unknown account is refused
