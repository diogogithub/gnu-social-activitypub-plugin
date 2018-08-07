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
 * ActivityPub error representation
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class Activitypub_accept extends Managed_DataObject
{
    /**
     * Generates an ActivityPub representation of a Accept
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param array $object
     * @return pretty array to be used in a response
     */
    public static function accept_to_array($object)
    {
        $res = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'     => common_root_url().'accept_follow_from_'.urlencode($object['actor']).'_to_'.urlencode($object['object']),
            'type'   => 'Accept',
            'actor'  => $object['object'],
            'object' => $object
        ];
        return $res;
    }

    /**
     * Verifies if a given object is acceptable for an Accept Activity.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Array $object
     * @throws Exception
     */
    public static function validate_object($object)
    {
        if (!is_array($object)) {
            throw new Exception('Invalid Object Format for Accept Activity.');
        }
        if (!isset($object['type'])) {
            throw new Exception('Object type was not specified for Accept Activity.');
        }
        switch ($object['type']) {
            case 'Follow':
                // Validate data
                if (!filter_var($object['object'], FILTER_VALIDATE_URL)) {
                    throw new Exception("Object is not a valid Object URI for Activity.");
                }
                break;
            default:
                throw new Exception('This is not a supported Object Type for Accept Activity.');
        }
        return true;
    }
}
