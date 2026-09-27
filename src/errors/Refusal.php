<?php

namespace Tahadudhiya\WebDoctor\errors;

use yii\base\InvalidArgumentException;

/**
 * A request Web Doctor turns down, in words written for the person who made it.
 *
 * Its message is translated text this plugin wrote, so a controller may show it. Any other
 * exception — including Yii's and Craft's own invalid-argument exceptions, whose messages can name
 * internals — is logged and answered generically instead.
 */
final class Refusal extends InvalidArgumentException
{
}
