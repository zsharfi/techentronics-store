# TechenTronics — WooCommerce store with WhatsApp ordering

Custom code behind **[techentronics.com](https://techentronics.com)**, an online store for a computer and mobile accessories retailer in Pakistan. Built and delivered by **Coding Time Labs**.

The store runs on WordPress + WooCommerce. Everything in this repository is the custom layer written for this project: six must-use plugins, the product templates, the stylesheet and the server hardening rules. WordPress, WooCommerce, the commercial theme, product photos and all credentials are deliberately left out.

## What the client needed

- A catalogue of about 400 products across five brands, many without a fixed price
- Orders confirmed over WhatsApp, because that is how the shop already sells, with cash on delivery
- A way for a non-technical owner to edit products and prices from his phone
- A fast, mobile-first shop that looks like one brand rather than a theme demo

## What was built

| Area | Details |
|---|---|
| WhatsApp ordering | Short checkout form, then a pre-filled WhatsApp message with the order. Products without a price show "Ask price on WhatsApp" instead of a cart button. On Android, links open the WhatsApp app directly and fall back to the web link if it is not installed. |
| Order workflow | Custom order statuses that match the shop's process: Awaiting WhatsApp confirmation → Confirmed → Packed → Dispatched → Completed. |
| Colour options | One product with several colours, each with its own photo and optional price. The chosen colour follows the product into the cart, the checkout and the order. |
| Product manager | A phone-friendly admin screen to list, search, add, edit, delete and restore products, change prices and stock in bulk, and manage photos, without the full WooCommerce editor. |
| Owner mode | When the owner is logged in, every product on the storefront gets a one-tap Edit button and a small "Manage store" dock. Customers never see it. |
| Store design | Custom product cards, product page layout with a sticky buy bar, and a single brand stylesheet for desktop and mobile. |
| Security | Login attempt limit, no user enumeration, XML-RPC off, no version leaks, security headers, and no script execution in the uploads folder. |

## Repository layout

```
mu-plugins/
  techentronics-whatsapp-orders.php   WhatsApp checkout, order statuses, price on request
  techentronics-store.php             Product cards, product page, colour options
  techentronics-product-manager.php   Owner's product manager screen
  techentronics-owner-mode.php        Storefront edit buttons and admin colour scheme
  techentronics-admin.php             Branded login and simplified dashboard
  techentronics-security.php          Hardening layer
  tt-templates/                       WooCommerce template overrides
css/techentronics.css                 Brand stylesheet (loaded as Additional CSS)
server/                               .htaccess rules for the site root and uploads folder
tools/import-lib.php                  Batch product importer used during setup
```

The plugins are "must-use" plugins: they live in `wp-content/mu-plugins/`, load automatically and keep working when the theme or other plugins are updated.

## Tech stack

WordPress 7.1 · WooCommerce 11 · PHP 8.3 · Elementor (homepage only) · LiteSpeed Cache · Yoast SEO

## Problems solved along the way

- **WhatsApp links failing on Android.** Some phones opened a blank web page instead of the app. Fixed by converting links to Android intents with a web fallback.
- **Mobile product gallery overflow.** A swipeable thumbnail row was widening the page. Fixed at the grid level so the main photo stays on screen.
- **Colour prices going stale.** Per-colour prices apply only while they agree with the product's main price, so an owner editing one price never leaves old colour prices behind.
- **Security headers missing on cached pages.** Headers are sent from both the server config and PHP so cached pages keep them.

## Notes

- This is a snapshot of client work, shared as a portfolio piece. It is not a drop-in plugin and is not licensed for reuse.
- The stylesheet is the main brand file; a few later tweaks live in the site's Customizer.
- Developed with AI-assisted tooling (Claude).
