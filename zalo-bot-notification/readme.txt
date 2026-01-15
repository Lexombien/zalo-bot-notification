=== Zalo Bot Notification ===
Contributors: lexombien
Tags: woocommerce, zalo, notification, bot, zalo-oa
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WooCommerce new order notifications to Zalo Bot instantly. Free, fast, and unlimited messages via Zalo Platform.

== Description ==

**Zalo Bot Notification for WooCommerce** helps store owners receive instant notifications on Zalo whenever a new order is placed. Instead of paying for expensive Zalo ZNS services, this plugin leverages the **Zalo Bot Platform (Free)** to deliver messages.

### Key Features:
*   🚀 **Instant Notification:** Receive Zalo messages immediately upon order placement.
*   💰 **Completely Free:** Use Zalo Bot infrastructure, no ZNS fees.
*   🔒 **Secure:** Settings stored as JSON String, tokens are sanitized.
*   🎨 **Customizable Template:** Support `{custom_field}` shortcode to fetch any order meta (Phone, Address, Total...).
*   👥 **Group Chat Support:** Can send notifications to Zalo Groups (using group Chat ID).
*   🛠 **Integrated Testing:** Real-data connection test tool included in the admin panel.

### Requirements:
*   WordPress website with WooCommerce installed.
*   A Zalo account to create a Bot (instructions included).

== Installation ==

1. Download the plugin (.zip).
2. Go to WordPress Dashboard -> **Plugins** -> **Add New** -> **Upload Plugin**.
3. Choose the zip file and install.
4. Activate the plugin.
5. Go to **Zalo Bot Notification** menu to configure Token and Chat ID.

== Frequently Asked Questions ==

= How do I get the Bot Token? =
You need to access [Zalo for Developers](https://developers.zalo.me/) or [Zalo Bot Platform](https://bot.zapps.me/) to create a new Bot. The token will be provided immediately after creation.

= Is there any maintenance fee? =
No. This plugin uses the free Zalo Bot API. However, Zalo might change their API policies in the future.

= Can I use this to message customers? =
Currently, the plugin focuses on **notifying Admins/Shop Owners**. Sending messages to customers (strangers who haven't interacted with the Bot) is restricted by Zalo to prevent spam.

== Screenshots ==

1. Simple and intuitive settings interface.
2. Order notification message sent to Zalo.

== Changelog ==

= 1.0.0 =
*   Initial release.
