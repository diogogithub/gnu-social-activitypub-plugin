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
 * ActivityPub's own Explorer
 *
 * Allows to discovery new (or the same) Profiles (both local or remote)
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class Activitypub_explorer
{
    private $discovered_actor_profiles = array();

    /**
     * Get every profile from the given URL
     * This function cleans the $this->discovered_actor_profiles array
     * so that there is no erroneous data
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $url User's url
     * @return array of Profile objects
     */
    public function lookup($url)
    {
        common_debug('ActivityPub Explorer: Started now looking for '.$url);
        $this->discovered_actor_profiles = array();

        return $this->_lookup($url);
    }

    /**
     * Get every profile from the given URL
     * This is a recursive function that will accumulate the results on
     * $discovered_actor_profiles array
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $url User's url
     * @return array of Profile objects
     */
    private function _lookup($url)
    {
        // First check if we already have it locally and, if so, return it
        // If the local fetch fails: grab it remotely, store locally and return
        if (! ($this->grab_local_user($url) || $this->grab_remote_user($url))) {
            throw new Exception('User not found.');
        }

        return $this->discovered_actor_profiles;
    }

    /**
     * This ensures that we are using a valid ActivityPub URI
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $url
     * @return boolean success state (related to the response)
     * @throws Exception (If the HTTP request fails)
     */
    private function ensure_proper_remote_uri($url)
    {
        $client    = new HTTPClient();
        $headers   = [];
        $headers[] = 'Accept: application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
        $headers[] = 'User-Agent: GNUSocialBot v0.1 - https://gnu.io/social';
        $response  = $client->get($url, $headers);
        $res = json_decode($response->getBody(), JSON_UNESCAPED_SLASHES);
        if (self::validate_remote_response($res)) {
            $this->temp_res = $res;
            return true;
        } else {
            common_debug('ActivityPub Explorer: Invalid potential remote actor while ensuring URI: '.$url. '. He returned the following: '.json_encode($res, JSON_UNESCAPED_SLASHES));
        }

        return false;
    }

    /**
     * Get a local user profile from its URL and joins it on
     * $this->discovered_actor_profiles
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $uri Actor's uri
     * @return boolean success state
     */
    private function grab_local_user($uri, $online = false)
    {
        if ($online) {
            common_debug('ActivityPub Explorer: Searching locally for '.$uri. ' with online resources.');
        } else {
            common_debug('ActivityPub Explorer: Searching locally for '.$uri. ' offline.');
        }
        // Ensure proper remote URI
        // If an exception occurs here it's better to just leave everything
        // break than to continue processing
        if ($online && $this->ensure_proper_remote_uri($uri)) {
            $uri = $this->temp_res["id"];
        }

        // Try standard ActivityPub route
        // Is this a known filthy little mudblood?
        $aprofile = self::get_aprofile_by_url($uri);
        if ($aprofile instanceof Activitypub_profile) {
            $profile = $aprofile->local_profile();
            common_debug('ActivityPub Explorer: Found a local Aprofile for '.$uri);
            // We found something!
            $this->discovered_actor_profiles[]= $profile;
            unset($this->temp_res); // IMPORTANT to avoid _dangerous_ noise in the Explorer system
            return true;
        } else {
            common_debug('ActivityPub Explorer: Unable to find a local Aprofile for '.$uri.' - looking for a Profile instead.');
            // Well, maybe it is a pure blood?
            // Iff, we are in the same instance:
            $ACTIVITYPUB_BASE_INSTANCE_URI_length = strlen(ACTIVITYPUB_BASE_INSTANCE_URI);
            if (substr($uri, 0, $ACTIVITYPUB_BASE_INSTANCE_URI_length) == ACTIVITYPUB_BASE_INSTANCE_URI) {
                try {
                    $profile = Profile::getByID(intval(substr($uri, $ACTIVITYPUB_BASE_INSTANCE_URI_length)));
                    common_debug('ActivityPub Explorer: Found a Profile for '.$uri);
                    // We found something!
                    $this->discovered_actor_profiles[]= $profile;
                    unset($this->temp_res); // IMPORTANT to avoid _dangerous_ noise in the Explorer system
                    return true;
                } catch (Exception $e) {
                    // Let the exception go on its merry way.
                    common_debug('ActivityPub Explorer: Unable to find a Profile for '.$uri);
                }
            }
        }

        // If offline grabbing failed, attempt again with online resources
        if (!$online) {
            common_debug('ActivityPub Explorer: Will try everything again with online resources against: '.$uri);
            return $this->grab_local_user($uri, true);
        }

        return false;
    }

    /**
     * Get a remote user(s) profile(s) from its URL and joins it on
     * $this->discovered_actor_profiles
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $url User's url
     * @return boolean success state
     */
    private function grab_remote_user($url)
    {
        common_debug('ActivityPub Explorer: Trying to grab a remote actor for '.$url);
        if (!isset($this->temp_res)) {
            $client    = new HTTPClient();
            $headers   = array();
            $headers[] = 'Accept: application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
            $headers[] = 'User-Agent: GNUSocialBot v0.1 - https://gnu.io/social';
            $response  = $client->get($url, $headers);
            $res = json_decode($response->getBody(), JSON_UNESCAPED_SLASHES);
        } else {
            $res = $this->temp_res;
            unset($this->temp_res);
        }
        if (isset($res["orderedItems"])) { // It's a potential collection of actors!!!
            common_debug('ActivityPub Explorer: Found a collection of actors for '.$url);
            foreach ($res["orderedItems"] as $profile) {
                if ($this->_lookup($profile) == false) {
                    common_debug('ActivityPub Explorer: Found an inavlid actor for '.$profile);
                    // XXX: Invalid actor found, not sure how we handle those
                }
            }
            // Go through entire collection
            if (!is_null($res["next"])) {
                $this->_lookup($res["next"]);
            }
            return true;
        } elseif (self::validate_remote_response($res)) {
            common_debug('ActivityPub Explorer: Found a valid remote actor for '.$url);
            $this->discovered_actor_profiles[]= $this->store_profile($res);
            return true;
        } else {
            common_debug('ActivityPub Explorer: Invalid potential remote actor while grabbing remotely: '.$url. '. He returned the following: '.json_encode($res, JSON_UNESCAPED_SLASHES));
        }

        return false;
    }

    /**
     * Save remote user profile in local instance
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param array $res remote response
     * @return Profile remote Profile object
     */
    private function store_profile($res)
    {
        // ActivityPub Profile
        $aprofile                 = new Activitypub_profile;
        $aprofile->uri            = $res['id'];
        $aprofile->nickname       = $res['preferredUsername'];
        $aprofile->fullname       = isset($res['name']) ? $res['name'] : null;
        $aprofile->bio            = isset($res['summary']) ? substr($res['summary'], 0, 1000) : null;
        $aprofile->inboxuri       = $res['inbox'];
        $aprofile->sharedInboxuri = isset($res['endpoints']['sharedInbox']) ? $res['endpoints']['sharedInbox'] : $res['inbox'];

        $aprofile->do_insert();
        $profile = $aprofile->local_profile();

        // Public Key
        $apRSA = new Activitypub_rsa();
        $apRSA->profile_id = $profile->getID();
        $apRSA->public_key = $res['publicKey']['publicKeyPem'];
        $apRSA->store_keys();

        return $profile;
    }

    /**
     * Validates a remote response in order to determine whether this
     * response is a valid profile or not
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param array $res remote response
     * @return boolean success state
     */
    public static function validate_remote_response($res)
    {
        if (!isset($res['id'], $res['preferredUsername'], $res['inbox'], $res['publicKey']['publicKeyPem'])) {
            return false;
        }

        return true;
    }

    /**
     * Get a ActivityPub Profile from it's uri
     * Unfortunately GNU Social cache is not truly reliable when handling
     * potential ActivityPub remote profiles, as so it is important to use
     * this hacky workaround (at least for now)
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $v URL
     * @return boolean|Activitypub_profile false if fails | Aprofile object if successful
     */
    public static function get_aprofile_by_url($v)
    {
        $i = Managed_DataObject::getcached("Activitypub_profile", "uri", $v);
        if (empty($i)) { // false = cache miss
            $i = new Activitypub_profile;
            $result = $i->get("uri", $v);
            if ($result) {
                // Hit!
                $i->encache();
            } else {
                return false;
            }
        }
        return $i;
    }

    /**
     * Given a valid actor profile url returns its inboxes
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param string $url of Actor profile
     * @return boolean|array false if fails | array with inbox and shared inbox if successful
     */
    public static function get_actor_inboxes_uri($url)
    {
        $client    = new HTTPClient();
        $headers   = array();
        $headers[] = 'Accept: application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
        $headers[] = 'User-Agent: GNUSocialBot v0.1 - https://gnu.io/social';
        $response  = $client->get($url, $headers);
        if (!$response->isOk()) {
            throw new Exception('Invalid Actor URL.');
        }
        $res = json_decode($response->getBody(), JSON_UNESCAPED_SLASHES);
        if (self::validate_remote_response($res)) {
            return [
                'inbox' => $res['inbox'],
                'sharedInbox' => isset($res['endpoints']['sharedInbox']) ? $res['endpoints']['sharedInbox'] : $res['inbox']
            ];
        }

        return false;
    }
}
