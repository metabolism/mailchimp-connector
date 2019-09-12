<?php
/**
* Mailchimp connector for Wordpress
*
* Plugin Name: Mailchimp connector
* Description: Link a custom post type to a Mailchimp campaign using Mailchimp API
* Version: 1.0.0
* Author: Metabolism
* License: GPLv2 or later
* License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

include __DIR__.'/lib/Mailchimp.php';

include __DIR__.'/src/Settings.php';
include __DIR__.'/src/MetaBox.php';
include __DIR__.'/src/Campaign.php';

use Metabolism\MailchimpConnector\Campaign;
use Metabolism\MailchimpConnector\Settings;
use Metabolism\MailchimpConnector\MetaBox;

new Settings();
new MetaBox();
new Campaign();