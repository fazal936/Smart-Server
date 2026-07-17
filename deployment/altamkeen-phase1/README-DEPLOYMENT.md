# AL TAMKEEN Phase 1 — Tasjeel shared-hosting deployment

This package is a standalone HTML5/CSS3/Bootstrap 5/JavaScript/PHP website. It contains no Odoo, SmartServe, Docker, Python, database, credentials, environment, or Git files.

## Contact-form configuration

Edit the constants near the top of `public_html/contact-submit.php` before upload if required:

- `CONTACT_TO`: mailbox that receives enquiries (`info@altamkeen.ae`)
- `CONTACT_FROM`: company-domain sender mailbox (`website@altamkeen.ae`)
- `COMPANY_NAME`: company display name
- `RATE_LIMIT_SECONDS`: minimum interval between submissions from one IP (60 seconds)

Create `website@altamkeen.ae` in cPanel, or replace `CONTACT_FROM` with another real mailbox on `altamkeen.ae`. The visitor email is used only as `Reply-To`, never as `From`.

## Tasjeel cPanel upload

1. Back up any current website files in cPanel File Manager.
2. In cPanel, create the `info@altamkeen.ae` recipient and `website@altamkeen.ae` sender mailboxes (or update the PHP constants to existing company-domain mailboxes).
3. Open File Manager and enter the domain's `public_html` document root.
4. Upload `altamkeen-phase1-tasjeel.zip` and extract it directly into `public_html`. `index.html`, `.htaccess`, and the other files must sit at the document root—not in an extra nested folder.
5. Ensure `contact-submit.php` is set to permission `0644`; folders should normally be `0755` and other files `0644`.
6. In cPanel MultiPHP Manager, select a supported PHP 8.x version for the domain. No framework, extension install, or database is required; PHP's `mail()` must be enabled.
7. Confirm the domain and `www` DNS records point to the Tasjeel account, then issue/enable the SSL certificate in cPanel.
8. Visit `http://www.altamkeen.ae` and confirm it redirects to `https://altamkeen.ae`.
9. Send a test enquiry and check inbox and spam. If PHP `mail()` is restricted, ask Tasjeel to enable local mail delivery or provide SMTP details for a future mailer integration.

## Final testing checklist

- HTTPS and non-`www` canonical redirects work with no loop.
- Home, About, Services, Contact, service anchors, logo, menu, and footer links work.
- Phone links dial the correct numbers; email and WhatsApp links open correctly.
- Desktop, tablet, and mobile navigation/layouts render cleanly.
- Every image loads; service images below the fold lazy-load.
- Each page has one clear H1, a unique title/description, canonical URL, and Open Graph data.
- `/robots.txt` and `/sitemap.xml` return HTTP 200.
- A nonexistent URL returns the branded 404 page with HTTP 404.
- A valid form request reaches `info@altamkeen.ae`; Reply sends to the visitor.
- Empty, invalid-email, invalid-phone, honeypot, too-fast, and repeated submissions show clear errors.
- No directory listing, Odoo route, backend/login link, Docker file, database, `.env`, or credential is exposed.
