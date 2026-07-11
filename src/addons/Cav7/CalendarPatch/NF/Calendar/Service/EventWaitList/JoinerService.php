<?php

namespace Cav7\CalendarPatch\NF\Calendar\Service\EventWaitList;

/**
 * The vendor's wait-list joiner throws on every instantiation. XF's
 * AbstractService::__construct calls setup() before the JoinerService
 * constructor has assigned its typed $event and $user properties, and the
 * vendor's setup() reads $this->getUser()->user_id, so it touches $user while
 * it is still uninitialized and PHP throws "Typed property ... must not be
 * accessed before initialization". The constructor then calls setup() a second
 * time, after both properties are assigned; that later call is the one that
 * actually builds the EventWaitList entity.
 *
 * Guard the premature first call: while $user is unset, skip setup() and let
 * the constructor's own later call run it once the properties exist. isset() is
 * false for an uninitialized typed property, so the guard needs no state of its
 * own. Once NF/Calendar reorders its constructor to assign the properties
 * before setup() runs, this extension can be retired.
 */
class JoinerService extends XFCP_JoinerService
{
    protected function setup(): void
    {
        if (!isset($this->user))
        {
            return; // the parent constructor fires setup() before the vendor assigns its properties
        }

        parent::setup();
    }
}
