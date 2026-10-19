# Deployment and testing

## Recommended workflow

1. Back up the WordPress database and plugin directory.
2. Install or update the plugin on a staging site.
3. Enable **Settings → Gym Store → Test Mode**.
4. Test checkout on iPhone and Android browsers, including Safari, Chrome, and
   privacy-focused browsers used by members.
5. Verify buyer name, purchased items, discount, transaction status, and
   monthly reporting in the staff Sales tab.
6. Confirm staff permissions with a Gym Store Cashier account.
7. Enter the production Venmo username and disable Test Mode only after the
   staging checks pass.
8. Deploy the same tested build to production and clear host/CDN caches.

## Test checklist

- Search and add multiple products
- Change quantities and remove a cart line
- Apply a valid and invalid coach code
- Start a test checkout and complete the return confirmation
- Confirm the payment as staff
- Edit a transaction and verify recalculated totals
- Mark a paid sale refunded
- Delete a test transaction
- Confirm that test, unpaid, submitted, and refunded sales are excluded from
  the monthly collected total and best-seller rankings
- Check the layout on a phone and tablet

## Creating the upload ZIP

From the repository root:

```bash
chmod +x scripts/package.sh
./scripts/package.sh
```

Upload `dist/gym-venmo-checkout-2.2.0.zip` through **Plugins → Add New Plugin →
Upload Plugin**. When updating, WordPress may ask whether to replace the current
plugin with the uploaded version.

## Cache troubleshooting

If an update is installed but the old interface remains visible, clear the
WordPress/hosting cache, purge any CDN cache, and reload the page in a private
browsing window.
