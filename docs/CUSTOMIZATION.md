# Customization

## Store settings

After activation, open **WordPress Admin → Settings → Gym Store**.

Configure:

- Gym name shown in the checkout header
- Venmo username used by the payment handoff
- Support email shown in the footer
- Sales-tax percentage
- Optional coach discount code (30%)
- Test Mode

No real payment can be started until a Venmo username is configured. Test Mode
can run without one.

## Products and prices

Edit `config/catalog.php`. Each entry contains the display name and price in
cents:

```php
return [
    ['Drop-in', 2500],
    ['Water', 100],
];
```

The plugin sorts products alphabetically. On the next plugin version change or
reactivation, the configured catalog is synchronized into WordPress. Products
removed from the file become inactive; historical sale lines remain intact.

## Product images

1. Add licensed JPEG, PNG, or WebP files to `assets/products/`.
2. Edit `config/product-images.php` using the exact catalog name:

```php
return [
    'Water' => 'water.webp',
];
```

Missing images automatically receive a visual placeholder.

## Styling

- `assets/app.css` contains the main interface styles.
- `assets/product-images.css` contains product-card and monthly-dashboard
  refinements.
- `assets/app.js` contains the storefront and staff sales interface.

The internal `csw_` database and CSS prefixes are retained for compatibility
with the original implementation. They do not expose customer information.

## Staff access

Create or edit a WordPress user and assign the **Gym Store Cashier** role. That
user can see the Sales tab and manage transactions but does not need the full
Administrator role.
