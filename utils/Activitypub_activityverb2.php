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
 * @copyright 2018 Free Software Foundation http://fsf.org
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      https://www.gnu.org/software/social/
 */
if (!defined('GNUSOCIAL')) {
    exit(1);
}

/**
 * Utility class to hold a bunch of constant defining default verb types
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class Activitypub_activityverb2 extends Managed_DataObject
{
    const FULL_LIST =
    [
        'Accept'          => 'https://www.w3.org/ns/activitystreams#Accept',
        'TentativeAccept' => 'https://www.w3.org/ns/activitystreams#TentativeAccept',
        'Add'             => 'https://www.w3.org/ns/activitystreams#Add',
        'Arrive'          => 'https://www.w3.org/ns/activitystreams#Arrive',
        'Create'          => 'https://www.w3.org/ns/activitystreams#Create',
        'Delete'          => 'https://www.w3.org/ns/activitystreams#Delete',
        'Follow'          => 'https://www.w3.org/ns/activitystreams#Follow',
        'Ignore'          => 'https://www.w3.org/ns/activitystreams#Ignore',
        'Join'            => 'https://www.w3.org/ns/activitystreams#Join',
        'Leave'           => 'https://www.w3.org/ns/activitystreams#Leave',
        'Like'            => 'https://www.w3.org/ns/activitystreams#Like',
        'Offer'           => 'https://www.w3.org/ns/activitystreams#Offer',
        'Invite'          => 'https://www.w3.org/ns/activitystreams#Invite',
        'Reject'          => 'https://www.w3.org/ns/activitystreams#Reject',
        'TentativeReject' => 'https://www.w3.org/ns/activitystreams#TentativeReject',
        'Remove'          => 'https://www.w3.org/ns/activitystreams#Remove',
        'Undo'            => 'https://www.w3.org/ns/activitystreams#Undo',
        'Update'          => 'https://www.w3.org/ns/activitystreams#Update',
        'View'            => 'https://www.w3.org/ns/activitystreams#View',
        'Listen'          => 'https://www.w3.org/ns/activitystreams#Listen',
        'Read'            => 'https://www.w3.org/ns/activitystreams#Read',
        'Move'            => 'https://www.w3.org/ns/activitystreams#Move',
        'Travel'          => 'https://www.w3.org/ns/activitystreams#Travel',
        'Announce'        => 'https://www.w3.org/ns/activitystreams#Announce',
        'Block'           => 'https://www.w3.org/ns/activitystreams#Block',
        'Flag'            => 'https://www.w3.org/ns/activitystreams#Flag',
        'Dislike'         => 'https://www.w3.org/ns/activitystreams#Dislike',
        'Question'        => 'https://www.w3.org/ns/activitystreams#Question'
    ];

    const KNOWN =
    [
        'Accept',
        'Create',
        'Delete',
        'Follow',
        'Like',
        'Undo',
        'Announce'
    ];

    /**
     * Converts canonical into verb.
     *
     * @author GNU Social
     * @param string $verb
     * @return string
     */
    public static function canonical($verb)
    {
        $ns = 'https://www.w3.org/ns/activitystreams#';
        if (substr($verb, 0, mb_strlen($ns)) == $ns) {
            return substr($verb, mb_strlen($ns));
        } else {
            return $verb;
        }
    }
}
