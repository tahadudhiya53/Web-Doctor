<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use craft\queue\Queue;

/**
 * Craft's own queue, standing in for the real one.
 *
 * The queue checks need an instance of Craft's queue to recognise, but read their numbers with
 * their own queries rather than through it — Craft's counting methods release timed-out jobs as
 * a side effect, which a diagnostic must not cause. So this exists only to be the right type;
 * what the queue contains is stated on the diagnostic itself.
 */
class StubQueue extends Queue
{
}
