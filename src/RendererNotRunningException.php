<?php

namespace RscKit;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The renderer is not answering.
 *
 * Thrown rather than returned so it renders as Laravel renders anything else
 * that goes wrong: the debug page while developing, with the message and a
 * stack trace, and an ordinary 502 in production.
 *
 * 502 specifically. This application is fine — it is the thing behind it that
 * is not answering — and a 500 would send whoever reads the log looking here.
 */
class RendererNotRunningException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(private ?string $url = null)
    {
        parent::__construct($url === null
            // Nothing to hand the request to. Almost always a dev server that
            // is not running yet, so this says what to run rather than what is
            // missing.
            ? 'No RSC renderer is running, so this page cannot be rendered. '
                .'Run `npm run dev` to start one (or `bun run dev`), or set '
                .'RSC_RENDERER_URL if it runs somewhere else.'
            // One was configured and did not answer, which is a different
            // problem: the address is worth printing, because it is usually
            // the wrong one rather than a dead process.
            : sprintf(
                'The RSC renderer at %s is not answering. Run `npm run dev` to start it '
                    .'(or `bun run dev`), or set RSC_RENDERER_URL if it runs somewhere else.',
                $url,
            ));
    }

    public function getStatusCode(): int
    {
        return 502;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    public function url(): ?string
    {
        return $this->url;
    }
}
