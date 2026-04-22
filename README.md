# Grindless SiteLink

Grindless SiteLink is a WordPress plugin that provides utilities and integrations for clients using the **Grindless Point of Sale (POS)** system. See grindless.com for information about the Grindless POS system.

This plugin acts as a bridge between a WordPress site and the Grindless ecosystem, enabling data synchronization and extending functionality for client websites. The features available in this plugin will only work when connected to the Grindless Point of Sale, which requires API credentials generated from the POS. Credentials are stored in WordPress' database on each client website and are unique per each company/client ("partition").

## Features

- **Grindless POS API Integration**  
  Includes libraries for interacting with the Grindless POS API.

- **Event Synchronization**  
  Sync in-store events with your website and display them using  
  [The Events Calendar](https://theeventscalendar.com/) (by Tribe).

- **Reservation Integration**  
  Pass online reservations directly into the client’s POS system.

- **Shared Utilities**  
  Common tools and helpers used across Grindless client implementations.

## Requirements

- WordPress (v6.9.4 or greater)
- PHP (v8.0 or greater)
- The Events Calendar plugin (for event-related features)

## Installation

1. Clone or download this repository into your WordPress plugins directory: wp-content/plugins/grindless-wp-sitelink, or download the latest ZIP file from the Releases section and upload it to your site's Plugins area.
2. Activate the plugin via the WordPress admin panel.
3. Configure settings as needed (see below).

## Configuration

Configuration will vary depending on the client setup. Typical steps include:

- Setting API credentials for the Grindless POS
	- Once installed, navigate to the WordPress admin area (/wp-admin) and click the Grindless tab on the left navigation menu.
	- From the Grindless settings panel, provide a PartitionID, Organization ID, and API Secret. Gather this information from the POS by navigating to Settings > API Access.
- Enabling/disabling specific integrations (events, reservations, etc.)
- Mapping data between POS and WordPress
	- If using events synchronization, you will need to add Venues from wp-admin (one Venue per store). For each Venue, add a Custom Field with the name of "org_id" and the value being that store's unique organization ID (find this in the POS under Settings > Manage Organizations).

> Note: Some features may require additional setup or coordination with the Grindless team.

## Usage

This plugin is primarily intended for developers working on Grindless client sites.

Depending on the implementation, it may:

- Automatically sync data from the POS
- Extend The Events Calendar with external data
- Provide hooks and utilities for custom integrations

## Development

This plugin is not intended for general public use. It is maintained for internal by Grindless clients. If you are a developer for a client that makes use of our POS software, you are invited to review and improve the software contained in this repository. To contribute, fork this repository and submit pull requests as per usual.

### Contributing

If you're working on a client project:

- Follow existing patterns in the codebase
- Add comments!
- Keep integrations modular when it makes sense to. If you are adding support for a specific feature, do not hard-code paths that are only relevant on your client's individual website. If you're adding a new feature, make it work for everyone.
- Avoid breaking existing client implementations

## Support

For questions or issues, contact the Grindless team [via email](mailto:admin+githubsupport@grindless.com), or open a ticket in GitHub.

## License
This plugin is covered by the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.en.html). You may copy, redistribute, and modify the software provided as part of this plugin (including for commercial or private use). Any alterations must be made available in a public repository (preferably also on GitHub). While this software is free and open source, it is still the property of Grindless LLC and subject to copyright.

## 3rd Party Software
This plugin makes use of the following 3rd-party software:
- [twilio-php](https://github.com/twilio/twilio-php): For sending text (SMS) messages to a Twilio account.
- [The Events Calendar](https://theeventscalendar.com/): For events related features.

*Grindless LLC offers no warranty for the above listed 3rd party resources.*


