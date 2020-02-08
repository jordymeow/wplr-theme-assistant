=== WP/LR Theme Assistant ===
Contributors: TigrouMeow
Tags: wplr-extension, lightroom, wplr-sync, assistant
Donate link: https://commerce.coinbase.com/checkout/d047546a-77a8-41c8-9ea9-4a950f61832f
Requires at least: 4.8
Tested up to: 5.2
Stable tag: 0.5.0

Extension for WP/LR Sync which allows creating mappings between your Lightroom data and the Post Types (often called Collection, Album, Portfolio...) and/or Taxonomies (Folder, Set...) of your theme.

== Description ==

Extension for WP/LR Sync which allows creating mappings between your Lightroom data and the Post Types (often called Collection, Album, Portfolio...) and/or Taxonomies (Folder, Set...) of your theme.

=== Post Types Extension ===

This plugin replaces the former extension called 'Post Types Extension' which was originally shipped with WP/LR Sync. It is currently exactly that extension, but it will evolve from now on.

=== Tutorial ===

It is strongly recommended to follow [this tutorial](https://meowapps.com/wplr-sync-theme-assistant/).

== Installation ==

1. Upload `wplr-theme-assistant` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

== Upgrade Notice ==

Replace all the files. Nothing else to do.

== Frequently Asked Questions ==

Nothing yet.

== Changelog ==

= 0.5.0 =
* Fix: Name was not initialized in some case.
* Add: IDs as strings for meta field.

= 0.4.8 =
* Add: 'Block for Meow Gallery' mode
* Add: 'Shortcode Block for Gallery' mode

= 0.4.6 =
* Fix: Avoid error if a collection with no name is sent by Lightroom.
* Fix: Doesn't do anything if no Mode is chosen.

= 0.4.5 =
* Fix: 'Call to a member function get_tags_from_media()' error.

= 0.4.4 =
* Fix: With Polylang, the order of images in translated posts wasn't updated.

= 0.4.3 =
* Add: Compatibility with Polylang.

= 0.4.2 =
* Fix: The mappings were all using the same meta so it was actualy working fine only for the first one.

= 0.2.4 =
* Fix: Fix when a registered post type is removed.

= 0.2.2 =
* Add: Handle multi-mappings thanks to a new UI and architecture.
* Add: Handle the new and optimized re-ordering process of WP/LR Sync.

= 0.1.4 =
* First release.