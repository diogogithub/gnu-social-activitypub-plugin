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

$valid_object_types = ['Note'];

// Validate data
if (!isset($data->object->id)) {
    ActivityPubReturn::error('Object ID not specified.');
} elseif (!filter_var($data->object->id, FILTER_VALIDATE_URL)) {
    ActivityPubReturn::error('Invalid Object ID.');
}
if (!(isset($data->object->type) && in_array($data->object->type, $valid_object_types))) {
    ActivityPubReturn::error('Invalid Object type.');
}
if (!isset($data->object->content)) {
    ActivityPubReturn::error('Object content was not specified.');
}
if (!isset($data->object->url)) {
    ActivityPubReturn::error('Object url was not specified.');
} elseif (!filter_var($data->object->url, FILTER_VALIDATE_URL)) {
    ActivityPubReturn::error('Invalid Object URL.');
}
if (!isset($data->object->to)) {
    ActivityPubReturn::error('Object To was not specified.');
}

$content = $data->object->content;

$act = new Activity();
$act->verb = ActivityVerb::POST;
$act->time = time();
$act->actor = $actor_profile->asActivityObject();

$act->context = new ActivityContext();

// Is this a reply?
if (isset($data->object->inReplyTo)) {
    try {
        $inReplyTo = ActivityPubPlugin::get_local_notice_from_url($data->object->inReplyTo);
    } catch (Exception $e) {
        ActivityPubReturn::error('Invalid Object inReplyTo value.');
    }
    $act->context->replyToID  = $inReplyTo->getUri();
    $act->context->replyToUrl = $inReplyTo->getUrl();
} else {
    $inReplyTo = null;
}

$discovery = new Activitypub_explorer;

if ($to_profiles == ['https://www.w3.org/ns/activitystreams#Public']) {
    $to_profiles = [];
}

// Generate Cc objects
if (isset($data->object->cc) && is_array($data->object->cc)) {
    // Remove duplicates from Cc actors set
    array_unique($data->object->to);
    foreach ($data->object->cc as $cc_url) {
        try {
            $to_profiles = array_merge($to_profiles, $discovery->lookup($cc_url));
        } catch (Exception $e) {
            // Invalid actor found, just let it go.
        }
    }
} elseif (empty($data->object->cc) || in_array($data->object->cc, $public_to)) {
    // No need to do anything else at this point, let's just break out the if
} else {
    try {
        $to_profiles = array_merge($to_profiles, $discovery->lookup($data->object->cc));
    } catch (Exception $e) {
        // Invalid actor found, just let it go.
    }
}

unset($discovery);

foreach ($to_profiles as $tp) {
    $act->context->attention[ActivityPubPlugin::actor_uri($tp)] = 'http://activitystrea.ms/schema/1.0/person';
}

// Add location if that is set
if (isset($data->object->latitude, $data->object->longitude)) {
    $act->context->location = Location::fromLatLon($data->object->latitude, $data->object->longitude);
}

// Reject notice if it is too long (without the HTML)
if (Notice::contentTooLong($content)) {
    ActivityPubReturn::error("That's too long. Maximum notice size is %d character.");
}

$options = array('source' => 'ActivityPub', 'uri' => $data->object->id, 'url' => $data->object->url);
// $options gets filled with possible scoping settings
ToSelector::fillActivity($this, $act, $options);

$actobj = new ActivityObject();
$actobj->type = ActivityObject::NOTE;
$actobj->content = strip_tags($content,'<p><b><i><u><a><ul><ol><li>');

// Finally add the activity object to our activity
$act->objects[] = $actobj;

try {
    $res = Activitypub_create::create_to_array(
            $data->id,
            $data->actor,
                    Activitypub_notice::notice_to_array(Notice::saveActivity($act, $actor_profile, $options))
        );
    ActivityPubReturn::answer($res);
} catch (Exception $e) {
    ActivityPubReturn::error($e->getMessage());
}
