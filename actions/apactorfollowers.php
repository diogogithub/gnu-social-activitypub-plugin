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
 * Actor's Followers Collection
 *
 * @category  Plugin
 * @package   GNUsocial
 * @author    Diogo Cordeiro <diogo@fc.up.pt>
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://www.gnu.org/software/social/
 */
class apActorFollowersAction extends ManagedAction
{
    protected $needLogin = false;
    protected $canPost   = true;

    /**
     * Handle the Followers Collection request
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @return void
     */
    protected function handle()
    {
        try {
            $profile = Profile::getByID($this->trimmed('id'));
            $profile_id = $profile->getID();
        } catch (Exception $e) {
            ActivityPubReturn::error('Invalid Actor URI.', 404);
        }

        if (!$profile->isLocal()) {
            ActivityPubReturn::error("This is not a local user.", 403);
        }

        if (!isset($_GET["page"])) {
            $page = 0;
        } else {
            $page = intval($this->trimmed('page'));
        }

        if ($page < 0) {
            ActivityPubReturn::error('Invalid page number.');
        }

        $since = ($page - 1) * PROFILES_PER_MINILIST;
        $limit = (($page - 1) == 0 ? 1 : $page) * PROFILES_PER_MINILIST;

        /* Calculate total items */
        $total_subs  = $profile->subscriberCount();
        $total_pages = ceil($total_subs / PROFILES_PER_MINILIST);

        $res = [
            '@context'     => [
              "https://www.w3.org/ns/activitystreams",
              "https://w3id.org/security/v1",
            ],
            'id'           => common_local_url('apActorFollowers', ['id' => $profile_id]).(($page != 0) ? '?page='.$page : ''),
            'type'         => ($page == 0 ? 'OrderedCollection' : 'OrderedCollectionPage'),
            'totalItems'   => $total_subs
        ];

        if ($page == 0) {
            $res['first'] = common_local_url('apActorFollowers', ['id' => $profile_id]).'?page=1';
        } else {
            $res['orderedItems'] = $this->generate_followers($profile, $since, $limit);
            $res['partOf'] = common_local_url('apActorFollowers', ['id' => $profile_id]);

            if ($page+1 < $total_pages) {
                $res['next'] = common_local_url('apActorFollowers', ['id' => $profile_id]).'page='.($page+1 == 1 ? 2 : $page+1);
            }

            if ($page > 1) {
                $res['prev'] = common_local_url('apActorFollowers', ['id' => $profile_id]).'?page='.($page-1 <= 0 ? 1 : $page-1);
            }
        }

        ActivityPubReturn::answer($res);
    }

    /**
     * Generates a list of stalkers for a given profile.
     *
     * @author Diogo Cordeiro <diogo@fc.up.pt>
     * @param Profile $profile
     * @param int32 $since
     * @param int32 $limit
     * @return Array of URIs
     */
    public static function generate_followers($profile, $since, $limit)
    {
        /* Fetch Followers */
        try {
            $sub = $profile->getSubscribers($since, $limit);
        } catch (NoResultException $e) {
            // Just let the exception go on its merry way
        }

        /* Get followers' URLs */
        $subs = [];
        while ($sub->fetch()) {
            $subs[] = ActivityPubPlugin::actor_uri($sub);
        }

        return $subs;
    }
}
