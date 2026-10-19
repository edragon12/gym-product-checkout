# Gym Product Checkout

A mobile-friendly WordPress checkout plugin for gyms and small studios that
hand off payments to Venmo and give staff a simple sales screen.

This repository is a reusable, sanitized edition of the checkout built for a
working gym. It contains no live Venmo username, discount code, customer data,
or third-party product photography.

## Features

- Fast product search, cart quantities, and a floating mobile pay button
- Venmo payment handoff with item names in the payment note
- Required buyer name with optional on-device remembering
- Unpaid, customer-submitted, confirmed, and refunded transaction states
- Staff-only sales access using the **Gym Store Cashier** WordPress role
- Edit, delete, refund, and manually confirm payment controls
- Optional 30% coach discount code
- Test mode that never opens Venmo or transfers money
- Monthly collected total, confirmed-sale count, and top five best sellers
- Optional locally packaged product images
- No inventory enforcement; every configured product remains active

## Important payment limitation

This plugin uses a Venmo web handoff, not a Venmo merchant API. A customer's
return to the site does not prove that money moved. Staff must verify the
payment in Venmo before clicking **Payment received**. Refunds must also be sent
separately in Venmo; the plugin only records the refunded status.

## Requirements

- WordPress 6.2 or newer
- PHP 7.4 or newer
- HTTPS on the production site
- A Venmo account permitted for the intended business use

## Install from source

1. Clone this repository into `wp-content/plugins/gym-venmo-checkout`.
2. Activate **Gym Venmo Checkout** in WordPress.
3. Open **Settings → Gym Store** and enter the gym name, Venmo username,
   support email, tax rate, and optional coach code.
4. Create a WordPress page and add `[gym_venmo_checkout]`.
5. Edit `config/catalog.php` for the vendor's products and prices.
6. Test the complete phone flow in staging before disabling Test Mode.

For a WordPress upload ZIP, run `./scripts/package.sh`, then upload the file
created in `dist/`.

## Documentation

- [Customization](docs/CUSTOMIZATION.md)
- [Deployment and testing](docs/DEPLOYMENT.md)
- [Security and operational notes](SECURITY.md)

## Privacy and assets

Buyer names and sales records are stored in the WordPress database. The
optional “remember my name” setting stores the buyer name only in that phone's
browser local storage. Review your own privacy, tax, record-retention, Venmo,
and consumer-disclosure obligations before production use.

Brand names and product images remain the property of their respective owners.
Add only assets you are authorized to use and redistribute.

## License

GNU General Public License v2.0 or later. See [LICENSE](LICENSE).
