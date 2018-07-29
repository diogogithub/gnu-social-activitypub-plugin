<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProfileObjectTest extends TestCase
{
    public function testLibraryInstalled()
    {
        $this->assertTrue(class_exists('\Activitypub_profile'));
    }

    public function testActivitypubProfile()
    {
        // Mimic proper ACCEPT header
        $_SERVER['HTTP_ACCEPT'] = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams';

        /* Test do_insert() */
        $aprofile = new \Activitypub_profile();
        $aprofile->uri = 'https://testinstance.net/index.php/user/1';
        $aprofile->nickname = 'test1';
        $aprofile->fullname = 'Test User 1';
        $aprofile->bio      = 'I am a nice test 1 guy';
        $aprofile->inboxuri = "https://testinstance.net/index.php/user/1/inbox.json";
        $aprofile->sharedInboxuri = "https://testinstance.net/inbox.json";
        $aprofile->do_insert();

        /* Test local_profile() */
        $profile = $aprofile->local_profile();

        /* Test from_profile() and create_from_local_profile() */
        $this->assertTrue($this->compare_aprofiles($aprofile, \Activitypub_profile::from_profile($profile)));

        /* Create Keys for Test User 1 */
        $apRSA = new \Activitypub_rsa();
        $apRSA->profile_id = $profile->getID();
        \Activitypub_rsa::generate_keys($apRSA->private_key, $apRSA->public_key);
        $apRSA->store_keys();

        /* Test profile_to_array() */
        // Fetch ActivityPub Actor Object representation
        $profile_array = \Activitypub_profile::profile_to_array($profile);
        // Check type
        $this->assertTrue(is_array($profile_array));
        // Test with Explorer's Profile Tester
        $this->assertTrue(\Activitypub_explorer::validate_remote_response($profile_array));

        /* Test get_inbox() */
        $this->assertTrue($aprofile->sharedInboxuri == $aprofile->get_inbox());

        /* Test getUri() */
        $this->assertTrue($aprofile->uri == $aprofile->getUri());

        /* Test getUrl() */
        $this->assertTrue($profile->getUrl() == $aprofile->getUrl());

        /* Test getID() */
        $this->assertTrue($profile->getID() == $aprofile->getID());

        /* Test fromUri() */
        $this->assertTrue($this->compare_aprofiles($aprofile, \Activitypub_profile::fromUri($aprofile->uri)));

        /* Remove Remote User Test 1 */
        $old_id = $profile->getID();
        $apRSA->delete();
        $aprofile->delete();
        $profile->delete();
        // Check if successfuly removed
        try {
            \Profile::getById($old_id);
            $this->assertTrue(false);
        } catch (\NoResultException $e) {
            $this->assertTrue(true);
        }

        /* Test ensure_web_finger() */
        // TODO: Maybe elaborate on this function's tests
        try {
            \Activitypub_profile::ensure_web_finger('test1@testinstance.net');
            $this->assertTrue(false);
        } catch (\Exception $e) {
            $this->assertTrue($e->getMessage() == 'Not a valid webfinger address.' ||
                              $e->getMessage() == 'Not a valid webfinger address (via cache).');
        }
    }

    // Helpers

    private function compare_profiles(\Profile $a, \Profile $b)
    {
        if (($av = $a->getID()) != ($bv = $b->getID())) {
            throw new Exception('Compare Profiles 1 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->getNickname()) != ($bv = $b->getNickname())) {
            throw new Exception('Compare Profiles 2 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->getFullname()) != ($bv = $b->getFullname())) {
            throw new Exception('Compare Profiles 3 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->getUrl()) != ($bv = $b->getUrl())) {
            throw new Exception('Compare Profiles 4 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->getDescription()) != ($bv = $b->getDescription())) {
            throw new Exception('Compare Profiles 5 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->getLocation()) != ($bv = $b->getLocation())) {
            throw new Exception('Compare Profiles 6 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->getNickname()) != ($bv = $b->getNickname())) {
            throw new Exception('Compare Profiles 7 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->lat) != ($bv = $b->lat)) {
            throw new Exception('Compare Profiles 8 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->lon) != ($bv = $b->lon)) {
            throw new Exception('Compare Profiles 9 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        return true;
    }

    private function compare_aprofiles(\Activitypub_profile $a, \Activitypub_profile $b)
    {
        if (($av = $a->getUri()) != ($bv = $b->getUri())) {
            throw new Exception('Compare AProfiles 1 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->getUrl()) != ($bv = $b->getUrl())) {
            throw new Exception('Compare AProfiles 2 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->getID()) != ($bv = $b->getID())) {
            throw new Exception('Compare AProfiles 3 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->profile_id) != ($bv = $b->profile_id)) {
            throw new Exception('Compare AProfiles 4 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->inboxuri) != ($bv = $b->inboxuri)) {
            throw new Exception('Compare AProfiles 5 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        if (($av = $a->sharedInboxuri) != ($bv = $b->sharedInboxuri)) {
            throw new Exception('Compare AProfiles 6 Fail: $a: '.$av.' is different from $b: '.$bv);
        }

        return true;
    }
}
