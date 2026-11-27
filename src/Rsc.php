<?php

namespace RscKit;

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

    /** Everything below the layouts, without re-rendering the layouts. */
    public const PAGE = 'page';
}
