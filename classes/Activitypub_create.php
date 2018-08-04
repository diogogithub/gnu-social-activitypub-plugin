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
class Activitypub_create extends Managed_DataObject
{
    /**
     * Generates an ActivityPub representation of a Create
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $actor
     * @param array $object
     * @return pretty array to be used in a response
     */
    public static function create_to_array($actor, $object)
    {
        $res = [
            '@context' => [
                    'https://www.w3.org/ns/activitystreams',
                    'https://w3id.org/security/v1'
            ],
            'id'     => $object.'/create',
            'type'   => 'Create',
            'to'     => $object['to'],
            'cc'     => $object['cc'],
            'actor'  => $actor,
            'object' => $object
        ];
        return $res;
    }

    /**
     * Verifies if a given object is acceptable for a Create Activity.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Array $object
     * @throws Exception
     */
    public static function validate_object($object)
    {
        if (!is_array($object)) {
            throw new Exception('Invalid Object Format for Create Activity.');
        }
        if (!isset($object['type'])) {
            throw new Exception('Object type was not specified for Create Activity.');
        }
        switch ($object['type']) {
            case 'Note':
                // Validate data
                Activitypub_notice::validate_note($object);
                break;
            default:
                throw new Exception('This is not a supported Object Type for Create Activity.');
        }
    }
}
