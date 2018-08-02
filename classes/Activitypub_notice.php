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
 * @author    Daniel Supernault <danielsupernault@gmail.com>
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @copyright 2018 Free Software Foundation http://fsf.org
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      https://www.gnu.org/software/social/
 */
if (!defined('GNUSOCIAL')) {
    exit(1);
}

/**
 * ActivityPub notice representation
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class Activitypub_notice extends Managed_DataObject
{
    /**
     * Generates a pretty notice from a Notice object
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Notice $notice
     * @return pretty array to be used in a response
     */
    public static function notice_to_array($notice)
    {
        $profile = $notice->getProfile();
        $attachments = [];
        foreach ($notice->attachments() as $attachment) {
            $attachments[] = Activitypub_attachment::attachment_to_array($attachment);
        }

        $tags = [];
        foreach ($notice->getTags() as $tag) {
            if ($tag != "") {       // Hacky workaround to avoid stupid outputs
                $tags[] = Activitypub_tag::tag_to_array($tag);
            }
        }

        $cc = [common_local_url('apActorFollowers', ['id' => $profile->getID()])];
        foreach ($notice->getAttentionProfiles() as $to_profile) {
            $cc[]  = $href = $to_profile->getUri();
            $tags[] = Activitypub_mention_tag::mention_tag_to_array_from_values($href, $to_profile->getNickname().'@'.parse_url($href, PHP_URL_HOST));
        }

        // In a world without walls and fences, we should make everything Public!
        $to[]= 'https://www.w3.org/ns/activitystreams#Public';

        $item = [
            '@context'     => 'https://www.w3.org/ns/activitystreams',
            'id'           => $notice->getUrl(),
            'type'         => 'Note',
            'published'    => str_replace(' ', 'T', $notice->getCreated()).'Z',
            'url'          => $notice->getUrl(),
            'atributtedTo' => ActivityPubPlugin::actor_uri($profile),
            'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc'           => $cc,
            'atomUri'      => $notice->getUrl(),
            'conversation' => $notice->getConversationUrl(),
            'content'      => $notice->getRendered(),
            'isLocal'      => $notice->isLocal(),
            'attachment'   => $attachments,
            'tag'          => $tags
        ];

        // Is this a reply?
        if (!empty($notice->reply_to)) {
            $item['inReplyTo'] = Notice::getById($notice->reply_to)->getUrl();
            $item['inReplyToAtomUri'] = Notice::getById($notice->reply_to)->getUrl();
        }

        // Do we have a location for this notice?
        try {
            $location = Notice_location::locFromStored($notice);
            $item['latitude']  = $location->lat;
            $item['longitude'] = $location->lon;
        } catch (Exception $e) {
            // Apparently no.
        }

        return $item;
    }

    /**
     * Create a Notice via ActivityPub data.
     * Returns created Notice.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $actor_profile
     * @param int32 $id
     * @param string $url
     * @param string $content
     * @param array|string $cc
     * @param array $settings possible keys: ['inReplyTo', 'latitude', 'longitude']
     * @return Notice
     * @throws Exception
     */
    public static function create_notice($actor_profile, $id, $url, $content, $cc, $settings)
    {
        $act = new Activity();
        $act->verb = ActivityVerb::POST;
        $act->time = time();
        $act->actor = $actor_profile->asActivityObject();

        $act->context = new ActivityContext();

        // Is this a reply?
        if (isset($settings['inReplyTo'])) {
            try {
                $inReplyTo = ActivityPubPlugin::grab_notice_from_url($settings['inReplyTo']);
            } catch (Exception $e) {
                throw new Exception('Invalid Object inReplyTo value: '.$e->getMessage());
            }
            $act->context->replyToID  = $inReplyTo->getUri();
            $act->context->replyToUrl = $inReplyTo->getUrl();
        } else {
            $inReplyTo = null;
        }

        $discovery = new Activitypub_explorer;

        // Generate Cc objects
        if (is_array($cc)) {
            // Remove duplicates from Cc actors set
            array_unique($cc);
            foreach ($cc as $cc_url) {
                try {
                    $cc_profiles = array_merge($cc_profiles, $discovery->lookup($cc_url));
                } catch (Exception $e) {
                    // Invalid actor found, just let it go. // TODO: Fallback to OStatus
                }
            }
        } elseif (empty($cc) || in_array($cc, ACTIVITYPUB_PUBLIC_TO)) {
            // No need to do anything else at this point, let's just break out the if
        } else {
            try {
                $cc_profiles = array_merge($cc_profiles, $discovery->lookup($cc));
            } catch (Exception $e) {
                // Invalid actor found, just let it go. // TODO: Fallback to OStatus
            }
        }

        unset($discovery);

        foreach ($cc_profiles as $tp) {
            $act->context->attention[ActivityPubPlugin::actor_uri($tp)] = 'http://activitystrea.ms/schema/1.0/person';
        }

        // Add location if that is set
        if (isset($settings['latitude'], $settings['longitude'])) {
            $act->context->location = Location::fromLatLon($settings['latitude'], $settings['longitude']);
        }

        // Reject notice if it is too long (without the HTML)
        if (Notice::contentTooLong($content)) {
            //throw new Exception('That\'s too long. Maximum notice size is %d character.');
        }

        $options = ['source' => 'ActivityPub', 'uri' => $id, 'url' => $url];

        $actobj = new ActivityObject();
        $actobj->type = ActivityObject::NOTE;
        $actobj->content = strip_tags($content, '<p><b><i><u><a><ul><ol><li>');

        // Finally add the activity object to our activity
        $act->objects[] = $actobj;

        try {
            return Notice::saveActivity($act, $actor_profile, $options);
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Validates a remote notice.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param  Array $data
     * @return boolean true in case of success
     * @throws Exception
     */
    public static function validate_remote_notice($data)
    {
        /*if (!isset($data['attributedTo'])) {
            common_debug('ActivityPub Notice Validator: Rejected because attributedTo was not specified.');
            throw new Exception('No attributedTo specified.');
        }
        if (!isset($data['id'])) {
            common_debug('ActivityPub Notice Validator: Rejected because Object ID was not specified.');
            throw new Exception('Object ID not specified.');
        } elseif (!filter_var($data['id'], FILTER_VALIDATE_URL)) {
            common_debug('ActivityPub Notice Validator: Rejected because Object ID is invalid.');
            throw new Exception('Invalid Object ID.');
        }
        if (!isset($data['type']) || $data['type'] !== 'Note') {
            common_debug('ActivityPub Notice Validator: Rejected because of Type.');
            throw new Exception('Invalid Object type.');
        }
        if (!isset($data['content'])) {
            common_debug('ActivityPub Notice Validator: Rejected because Content was not specified.');
            throw new Exception('Object content was not specified.');
        }
        if (!isset($data['url'])) {
            throw new Exception('Object URL was not specified.');
        } elseif (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            common_debug('ActivityPub Notice Validator: Rejected because Object URL is invalid.');
            throw new Exception('Invalid Object URL.');
        }
        if (!isset($data['cc'])) {
            common_debug('ActivityPub Notice Validator: Rejected because Object CC was not specified.');
            throw new Exception('Object CC was not specified.');
        }*/
        return true;
    }
}
