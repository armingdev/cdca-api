<?php

namespace App\Game\Exceptions;

/**
 * Something went wrong that says nothing about whether the action is possible:
 * an NPC that happens not to be standing in its room this minute, a page that
 * came back malformed. The same attempt a few minutes later may well succeed.
 *
 * The distinction earns its keep in quest lists, where every GameException
 * used to mean "skip this quest for the rest of the run" — so one bad page
 * read cost a quest the character could have completed.
 */
class TransientGameException extends GameException {}
