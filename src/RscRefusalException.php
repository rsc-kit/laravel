<?php

namespace RscKit;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Declining on purpose, with a message for the person asking and data the
 * page can act on - the records blocking a delete, as links.
 *
 * An HttpException, so anywhere outside a host call it is an ordinary abort
 * with its status. Through a host call it travels as `refusalData` beside the
 * message, and the renderer raises it as the action client's own refuse(): the
 * message is shown as written, the data reaches the page as `result.refusal`
 * once the action's `.refusal(schema)` has checked it.
 *
 * Thrown by Rsc::refuse().
 */
class RscRefusalException extends HttpException
{
    public function __construct(string $message, private mixed $data = null, int $status = 409)
    {
        parent::__construct($status, $message);
    }

    /** What the page can act on beside the message. Null for a refusal that only says why. */
    public function getData(): mixed
    {
        return $this->data;
    }
}
