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

        $to = [];
        foreach ($notice->getAttentionProfiles() as $to_profile) {
            $to[]  = $href = $to_profile->getUri();
            $tags[] = Activitypub_mention_tag::mention_tag_to_array_from_values($href, $to_profile->getNickname().'@'.parse_url($href, PHP_URL_HOST));
        }

        // In a world without walls and fences, we should make everything Public!
        $to[]= 'https://www.w3.org/ns/activitystreams#Public';

        $item = [
                '@context'          => 'https://www.w3.org/ns/activitystreams',
                'id'               => $notice->getUrl(),
                'type'             => 'Note',
                'published'        => str_replace(' ', 'T', $notice->getCreated()).'Z',
                'url'              => $notice->getUrl(),
                'atributtedTo'      => ActivityPubPlugin::actor_uri($profile),
                'to'               => $to,
                'cc'               => common_local_url('apActorFollowers', ['id' => $profile->getID()]),
                'atomUri'          => $notice->getUrl(),
                'conversation'     => $notice->getConversationUrl(),
                'content'          => $notice->getContent(),
                'isLocal'         => $notice->isLocal(),
                'attachment'       => $attachments,
                'tag'              => $tags
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
        } catch (Exception $ex) {
            // Apparently no.
        }

        return $item;
    }
}
