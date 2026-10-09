<?php

/**
 * Whether the skill an agent reads still says what the framework does.
 *
 * The skill is a hand-written copy of the engine's behaviour, in a different
 * repository from the engine, and a copy is only as current as the last person
 * who remembered. This is the memory: every behaviour an app author can observe
 * or has to act on is a line below, with the text that proves the skill says it.
 * rsc-kit's packages/mcp/tests/coverage.test.ts keeps the same list for the
 * how_to recipes and the guides; add a line to both in the same pass as the
 * change, and merge this one after the release that carries it.
 */
// Whitespace collapsed: the skill wraps its lines, and a phrase may straddle one.
$skill = preg_replace('/\s+/', ' ', file_get_contents(dirname(__DIR__, 2).'/resources/boost/skills/laravel-rsc-development/SKILL.md'));

dataset('what an agent must be told', [
    'Rsc::refuse declines on purpose, with data' => ['Rsc::refuse('],
    'a refusal keeps its message, and a bare abort() says "Refused."' => ['Refused.'],
    'through a generated stub only the message arrives' => ['Through a generated stub'],
    'formRefusal is typed from the form\'s action' => ['formRefusal'],
    'a redirected stub call resolves { redirected }, narrowed by isRedirected' => ['isRedirected'],
    'a redirect read as text is caught by type-aware lint' => ['restrict-template-expressions'],
    'a page never answers an image, script, stylesheet or font request' => ['Sec-Fetch-Dest'],
    'a late notFound() shows not-found.tsx where the page was' => ['where the page was'],
    'a not-found.tsx beside a layout answers the pages under it; the nearest wins' => ['the nearest one above the page wins'],
    'a stub awaited directly rejects with ActionRefusedError when the backend refuses it' => ['ActionRefusedError'],
    'a stub whose input was refused rejects with ServerValidationError' => ['ServerValidationError'],
    'a server-only engine module in the browser bundle fails the build' => ['ended up in the browser bundle'],
    'error() and clearErrors() are typed from the action input' => ['FieldNamesOf'],
    'a wrapper around a stub declares its form fields with FormFields' => ['FormFields'],
    'a query takes its schema input, and cannot redirect' => ['It cannot redirect'],
    'a crawler is answered once the page has finished' => ['crawler'],
    'app.markup(path) is the page without its scripts' => ['app.markup(path)'],
    'a client component is tested with the DOM registered by the first import' => ["import './dom'"],
    'createTestApp refuses to run while a DOM is registered globally' => ['`createTestApp` refuses to run'],
]);

it('says it', function (string $needle) use ($skill) {
    expect(str_contains($skill, $needle))->toBeTrue("the Boost skill does not say it: {$needle}");
})->with('what an agent must be told');
