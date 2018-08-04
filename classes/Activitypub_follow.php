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

/**
 * ActivityPub error representation
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class Activitypub_follow extends Managed_DataObject
{
    /**
     * Generates an ActivityPub representation of a subscription
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $actor
     * @param string $object
     * @return pretty array to be used in a response
     */
    public static function follow_to_array($actor, $object)
    {
        $res = [
            '@context' => [
                    'https://www.w3.org/ns/activitystreams',
                    'https://w3id.org/security/v1'
            ],
            'id'     => common_root_url().'follow_from_'.urlencode($actor).'_to_'.urlencode($object),
            'type'   => 'Follow',
            'actor'  => $actor,
            'object' => $object
       ];
        return $res;
    }

    /**
     * Handles a Follow Activity received by our inbox.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $actor_profile Remote Actor
     * @param string $object Local Actor
     * @throws Exception
     */
    public static function follow($actor_profile, $object)
    {
        // Get Actor's Aprofile
        $actor_aprofile = Activitypub_profile::from_profile($actor_profile);

        // Get Object profile
        $object_profile = new Activitypub_explorer;
        $object_profile = $object_profile->lookup($object)[0];

        if (!Subscription::exists($actor_profile, $object_profile)) {
            Subscription::start($actor_profile, $object_profile);
            common_debug('ActivityPubPlugin: Accepted Follow request from '.ActivityPubPlugin::actor_uri($actor_profile).' to '.$object);
        } else {
            common_debug('ActivityPubPlugin: Received a repeated Follow request from '.ActivityPubPlugin::actor_uri($actor_profile).' to '.$object);
        }

        // Notify remote instance that we have accepted their request
        common_debug('ActivityPubPlugin: Notifying remote instance that we have accepted their Follow request request from '.ActivityPubPlugin::actor_uri($actor_profile).' to '.$object);
        $postman = new Activitypub_postman($actor_profile, [$actor_aprofile]);
        $postman->accept_follow();
    }
}
