# External Image Importer

A WordPress plugin that automatically downloads and replaces external image URLs in posts and pages with local copies, helping you avoid hotlinking and improve site performance.

## Features

- Automatically imports external images when saving posts or pages.
- Replaces external image URLs in post content with local media URLs.
- Supports configurable post types to target.
- Domain whitelist to limit which external domains are imported.
- Settings page with options to enable/disable import and configure options.
- Manual bulk import button to import images from all existing posts/pages.
- Lightweight and easy to use.

## Installation

1. Download or clone this repository.
2. Upload the plugin folder to your WordPress `/wp-content/plugins/` directory.
3. Activate the plugin through the WordPress admin dashboard.
4. Go to **Settings > External Image Importer** to configure options.
5. Use the manual import button to bulk import external images from existing content.

## Usage

- On post save, external images will be automatically downloaded and replaced with local URLs.
- Configure which post types to process and domain whitelist on the settings page.
- Run the manual import anytime to process all published posts and pages.

## Frequently Asked Questions

**Q:** Will this import images from any domain?  
**A:** By default, all domains are allowed. You can restrict importing to specific domains by setting the whitelist in the plugin settings.

**Q:** Does this plugin replace images in custom post types?  
**A:** Yes, you can select any public post types in the settings to enable image importing.

**Q:** What happens if an image cannot be downloaded?  
**A:** The plugin skips images that fail to download and logs errors silently.

## Contributing

Contributions and bug reports are welcome! Feel free to open an issue or submit a pull request.

## License

This plugin is licensed under the GPLv2 or later.
