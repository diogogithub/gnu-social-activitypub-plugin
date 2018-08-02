<?php
/**
 * GNU social - a federating social network
 *
 * ActivityPubPlugin implementation for GNU Social
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @author    Daniel Supernault <danielsupernault@gmail.com>
 * @copyright 2018 Free Software Foundation http://fsf.org
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      https://www.gnu.org/software/social/
 */
if (!defined('GNUSOCIAL')) {
    exit(1);
}

// Validate Object
if (!filter_var($data['object'], FILTER_VALIDATE_URL)) {
    ActivityPubReturn::error('Invalid Object Actor URL.');
}

// Ensure valid Object profile
try {
    if (!isset($profile)) {
        $object_profile = new Activitypub_explorer;
        $object_profile = $object_profile->lookup($data['object'])[0];
    } else {
        $object_profile = $profile;
        unset($profile);
    }
} catch (Exception $e) {
    ActivityPubReturn::error('Invalid Object Actor URL.', 404);
}

// Get Actor's Aprofile
$actor_aprofile = Activitypub_profile::from_profile($actor_profile);

if (!Subscription::exists($actor_profile, $object_profile)) {
    Subscription::start($actor_profile, $object_profile);
    common_debug('ActivityPubPlugin: Accepted Follow request from '.$data['actor'].' to '.$data['object']);

    // Notify remote instance that we have accepted their request
    common_debug('ActivityPubPlugin: Notifying remote instance that we have accepted their Follow request request from '.$data['actor'].' to '.$data['object']);
    $postman = new Activitypub_postman($actor_profile, [$actor_aprofile]);
    $postman->follow();
    ActivityPubReturn::answer();
} else {
    common_debug('ActivityPubPlugin: Received a repeated Follow request from '.$data['actor'].' to '.$data['object']);
    ActivityPubReturn::error('Already following.', 202);
}
