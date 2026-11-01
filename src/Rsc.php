<?php

namespace RscKit;

use Illuminate\Support\Facades\Cookie;
use InvalidArgumentException;

/**
 * The entry point an action reaches for.
 *
 *     Rsc::revalidate('orders');
 *
 * Says that whatever the action just changed makes that part of the page
 * stale. The answer to the action carries the re-rendered part back with it,
 * rather than the browser asking again once it has been told.
 *
 *     Rsc::changed("team:$teamId:repos");
 *
 * Says that data a page names by name changed - from anywhere, not only an
 * action - and every open tab showing it refreshes.
 *
 *     Rsc::refuse('Still in use', ['blockers' => $links]);
 *
 * Declines on purpose, with a message and data the page can act on.
 *
 *     Rsc::flash('Invoice sent.');
 *
 * Leaves a message for the page the visitor lands on next, shown once by
 * useFlash().
 */
class Rsc
{
    public static function revalidate(string ...$targets): void
    {
        app(Revalidation::class)->mark(...$targets);
    }

    /**
     * Say these names changed, so every open tab showing them refreshes.
     *
     *     Rsc::changed("team:$teamId:repos");   // from a webhook, a job, a listener
     *
     * Unlike revalidate(), not tied to an action's answer: a version moves
     * in the cache, and the renderer's watchers see it. See Versions.
     */
    public static function changed(string ...$names): void
    {
        app(Versions::class)->changed(...$names);
    }

    /**
     * Decline, with a message for the person asking and data the page can act on.
     *
     *     if ($project->orders()->exists()) {
     *         Rsc::refuse('Still in use', ['blockers' => $project->orders->map(fn ($o) => [
     *             'id' => $o->id, 'href' => route('orders.show', $o),
     *         ])]);
     *     }
     *
     * The message is shown as written - never replaced by a generic one - and
     * the data reaches the page as the action's `result.refusal`, once its
     * `.refusal(schema)` has checked it. 409 unless told otherwise.
     */
    public static function refuse(string $message, mixed $data = null, int $status = 409): never
    {
        throw new RscRefusalException($message, $data, $status);
    }

    /** The cookie flash() writes and useFlash() reads - the engine's name, so the two meet. */
    public const FLASH_COOKIE = 'rsc-flash';

    /** The kinds useFlash() hands the page; anything else is a typo, not a fifth kind. */
    public const FLASH_KINDS = ['success', 'error', 'info', 'warning'];

    /**
     * Leave a message for the next page the visitor sees, shown once.
     *
     *     Rsc::flash('Invoice sent.');
     *     Rsc::flash('The card was declined.', 'error');
     *
     * The same message @rsc-kit/core/flash leaves, so a page's useFlash()
     * shows it whichever side wrote it: from an action called by rpc(), the
     * cookie rides the host call's answer onto the page, as a login does; from
     * a controller, on its own response. Several in one request are all shown.
     *
     * The cookie is read by the browser, so it is not encrypted - the provider
     * exempts it - and it is not for anything a visitor should not read.
     */
    public static function flash(string $message, string $kind = 'success'): void
    {
        if (! in_array($kind, self::FLASH_KINDS, true)) {
            throw new InvalidArgumentException("A flash is one of success, error, info or warning, not [{$kind}].");
        }

        // A message already queued in this request, or one the visitor still
        // carries from a page they have not reached, keeps its place.
        $queued = Cookie::queued(self::FLASH_COOKIE)?->getValue() ?? request()->cookie(self::FLASH_COOKIE);
        $messages = is_string($queued) ? json_decode($queued, true) : null;
        $messages = is_array($messages) && array_is_list($messages) ? $messages : [];

        $messages[] = ['message' => $message, 'kind' => $kind];

        Cookie::queue(Cookie::make(
            self::FLASH_COOKIE,
            json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            minutes: 1,
            path: '/',
            secure: null,
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /** Everything below the layouts, without re-rendering the layouts. */
    public const PAGE = 'page';
}
