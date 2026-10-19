# Security and operations

## Supported release

The current reusable release is 2.2.0.

## Payment verification

The Venmo handoff cannot verify a payment. Treat **Customer submitted** as a
claim from the customer, not proof of payment. Staff should compare the buyer,
amount, and note in Venmo before marking a transaction received.

Never store a Venmo password, access token, bank credential, or API secret in
this plugin.

## WordPress access

- Keep WordPress, PHP, this plugin, and other plugins patched.
- Use HTTPS and strong unique passwords.
- Give store staff the Gym Store Cashier role instead of Administrator.
- Restrict WordPress administration with multifactor authentication where
  available.
- Back up the database before plugin upgrades.

## Data handling

The plugin stores buyer names, order lines, totals, payment state, and staff
user IDs in custom WordPress database tables. Establish an appropriate privacy
notice and retention policy for the deploying organization.

To report a vulnerability, use the private security-reporting channel of the
GitHub repository instead of opening a public issue containing exploit details
or customer data.
