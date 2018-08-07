#!/usr/bin/env php
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

define('INSTALLDIR', realpath(__DIR__ . '/../../..'));

$shortoptions = 'u:af';
$longoptions = ['uri=', 'all', 'force'];

$helptext = <<<END_OF_HELP
update_activitypub_profiles.php [options]
Refetch / update ActivityPub RSA keys, profile info and avatars. Useful if you
do something like accidentally delete your avatars directory when
you have no backup.

END_OF_HELP;

require_once INSTALLDIR.'/scripts/commandline.inc';

$quiet = have_option('q', 'quiet');

if (!$quiet) {
    echo "ActivityPub Profiles updater will now start!\n";
    echo "Summoning Diogo Cordeiro, Richard Stallman and Chuck Norris to help us with this task!\n";
}

$user = new Activitypub_profile();
$cnt = $user->find();
if (!empty($cnt)) {
    if (!$quiet) {
        echo "Found {$cnt} ActivityPub profiles:\n";
    }
} else {
    if (have_option('u', 'uri')) {
        if (!$quiet) {
            echo "Couldn't find an existing ActivityPub profile with that URI.\n";
        }
    } else {
        if (!$quiet) {
            echo "Couldn't find any existing ActivityPub profiles.\n";
        }
    }
    exit(0);
}
while ($user->fetch()) {
    try {
        $res = grab_remote_user($user->uri);
    } catch (Exception $e) {
        // let it go
    }
    if (!$quiet) {
        echo "Updated ".update_profile($user, $res)->getBestName()."\n";
    }
}

/**
 * Update Profile and ActivityPub Profile objects
 *
 * @author Diogo Cordeiro <diogo@fc.up.pt>
 * @access public
 */
function do_update($aprofile)
{
    $profile = $aprofile->local_profile();

    $profile->modified = $aprofile->modified = common_sql_now();

    $fields = [
                'uri'      => 'profileurl',
                'nickname' => 'nickname',
                'fullname' => 'fullname',
                'bio'      => 'bio'
                ];

    foreach ($fields as $af => $pf) {
        $profile->$pf = $aprofile->$af;
    }

    $profile->update();
    $aprofile->update();
}

/**
 * Save remote user profile in local instance
 *
 * @author Diogo Cordeiro <diogo@fc.up.pt>
 * @param array $res remote response
 * @return Profile remote Profile object
 */
function update_profile($aprofile, $res)
{
    // ActivityPub Profile
    $aprofile->uri            = $res['id'];
    $aprofile->nickname       = $res['preferredUsername'];
    $aprofile->fullname       = isset($res['name']) ? $res['name'] : null;
    $aprofile->bio            = isset($res['summary']) ? substr(strip_tags($res['summary']), 0, 1000) : null;
    $aprofile->inboxuri       = $res['inbox'];
    $aprofile->sharedInboxuri = isset($res['endpoints']['sharedInbox']) ? $res['endpoints']['sharedInbox'] : $res['inbox'];

    do_update($aprofile);
    $profile = $aprofile->local_profile();

    // Public Key
    $apRSA = new Activitypub_rsa();
    $apRSA->profile_id = $profile->getID();
    $apRSA->public_key = $res['publicKey']['publicKeyPem'];
    $apRSA->modified = common_sql_now();
    if(!$apRSA->update())
        $apRSA->insert();

    // Avatar
    if (isset($res['icon']['url'])) {
        try {
            Activitypub_explorer::update_avatar($profile, $res['icon']['url']);
        } catch (Exception $e) {
            // Let the exception go, it isn't a serious issue
            common_debug('An error ocurred while grabbing remote avatar'.$e->getMessage());
        }
    }

    return $profile;
}

/**
 * Get a remote user(s) profile(s) from its URL
 *
 * @author Diogo Cordeiro <diogo@fc.up.pt>
 * @param string $url User's url
 * @return boolean success state
 */
function grab_remote_user($url)
{
    $client    = new HTTPClient();
    $headers   = [];
    $headers[] = 'Accept: application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
    $headers[] = 'User-Agent: GNUSocialBot v0.1 - https://gnu.io/social';
    $response  = $client->get($url, $headers);
    $res = json_decode($response->getBody(), true);
    if (Activitypub_explorer::validate_remote_response($res)) {
        common_debug('ActivityPub Explorer: Found a valid remote actor for '.$url);
        return $res;
    }
    throw new Exception('Failed to grab.');
}
