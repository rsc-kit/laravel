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
    'a team-scoped stub is a form action once its team is bound' => ['bind(null, team)'],
    'a change straight in the database says nothing to open tabs' => ['straight in the database'],
    'backendPaths forwards a backend prefix before any page is matched' => ['backendPaths'],
    'a url named in backendPaths is a document load in the browser, never prefetched, so it may bounce to another site' => ['never a page in the browser'],
    'a backendPaths prefix is typed as a Route, so redirect() to it needs no cast' => ['needs no cast'],
    'a GET Form whose action is the page on screen keeps the page up while the query runs, pending until the results land' => ['search on the page it is on'],
    'a page\'s own share image is an opengraph-image file in its folder, and an exported one replaces the site\'s' => ['opengraph-image'],
    'a src/commands file is the renderer\'s own release step or worker, not Laravel\'s migrate or queue' => ['src/commands'],
    'a src/commands file does not import instrumentation.ts unless it exports register = true' => ['does not import `instrumentation.ts` unless it exports `register = true`'],
    'a page also refreshes the sections beside its layouts, nearest first, so what every tab shares lives in the layout' => ['beside its layouts'],
    'a section is exported by a name, so an editor offers the component' => ['export default Repos'],
    'a query takes its schema input, and cannot redirect' => ['It cannot redirect'],
    'error.tsx reset() asks the server for the page again, so Try again recovers' => ['asks the server for the page again'],
    'a crawler is answered once the page has finished' => ['crawler'],
    'app.markup(path) is the page without its scripts' => ['app.markup(path)'],
    'a client component is tested with the DOM registered by the first import' => ["import './dom'"],
    'createTestApp refuses to run while a DOM is registered globally' => ['`createTestApp` refuses to run'],
    'a built renderer started with no NODE_ENV sets the one it was built with' => ['started with no `NODE_ENV` sets the one it was built with'],
    'an admin resource\'s form is any Standard Schema; one without Standard JSON Schema lists its fields' => ['any Standard Schema'],
    'an admin number field arrives as a number: z.number(), not z.coerce.number()' => ['not z.coerce.number()'],
    'an admin data source is five functions told the context { tenant }, and find answering null is a 404' => ['{ tenant }'],
    'an admin table in client mode is listed once with perPage: null' => ['perPage: null'],
    'an admin panel\'s authorize runs on every page and every save, delete and switch' => ['authorize'],
    'an admin panel refuses a current tenant that is not one of the visitor\'s' => ['tenants()'],
    'an admin panel is mounted by seven route files, and the section is admin-table.section.tsx' => ['admin-table.section.tsx'],
    'an admin plugin is published with the rsc-kit-admin-plugin keyword' => ['rsc-kit-admin-plugin'],
    'an admin form is checked by the browser first, and the server still checks every save' => ['Checked in the browser first'],
    'the admin theme follows the system, and the root <html> needs suppressHydrationWarning' => ['suppressHydrationWarning'],
    'a Laravel 422 from an admin source shows under the form\'s fields, uncaught' => ['Nothing to catch'],
    'a FormRequest callable receives the request alone, so an admin update sends its id in the values' => ['no argument after it'],
    'a section is named by its file, and a different section() name fails the build' => ['name it after its file'],
    'a one-time message survives a redirect: Rsc::flash() in the action, useFlash() in the root layout' => ['useFlash'],
    'a backend leaves the same one-time message with Rsc::flash()' => ["Rsc::flash('"],
    'an admin relation is a select of another resource\'s rows, listed and checked as the visitor' => ["relation: 'categories'"],
    'an admin bulk action is given rows found as the visitor, after authorize' => ['bulkActions'],
    'export const revalidate = N lets a shared cache keep a page that read nothing of the visitor, and its rpc() calls reach Laravel as nobody' => ['export const revalidate'],
    'a route.ts POST/PUT/PATCH/DELETE from another site is 403 with no token; trustedOrigins names exceptions' => ['trustedOrigins'],
    'a kept page carries the tags of the cache() answers it used as Cache-Tag' => ['Cache-Tag'],
    'the admin panel is @rsc-kit/press: definePanel from /admin, drawn by a theme from /shadcn; @rsc-kit/admin is gone' => ['@rsc-kit/press/shadcn'],
    'a panel theme is an object of components; spread shadcn to replace one page' => ['...shadcn'],
    'a redirect from an action keeps the layouts unless Rsc::revalidate(\'all\') comes before it' => ["revalidate('all') before"],
    'a public form is defineForm from @rsc-kit/press/forms, in a page that stays static' => ['defineForm'],
    'a public form drops a submission whose homepage bot trap is filled' => ['homepage'],
    'pages an editor builds are definePages from @rsc-kit/press/pages, served by one catch-all' => ['definePages'],
    'a pages catch-all needs site.middleware, or a missing page answers 200' => ['site.middleware'],
    'a pages source list() must honour filters: { slug }' => ['filters: { slug }'],
    "a press page is kept between requests and a panel save calls changed('press:pages')" => ["changed('press:pages')"],
    'an upload field is { type: \'image\' | \'file\', storage } and put answers the url the record saves' => ["type: 'image'"],
    'the renderer keeps a public revalidate page itself, serves it stale while one render replaces it, and Rsc::changed() makes it render on every instance' => ['X-RSC-Kit: cached'],
]);

it('says it', function (string $needle) use ($skill) {
    expect(str_contains($skill, $needle))->toBeTrue("the Boost skill does not say it: {$needle}");
})->with('what an agent must be told');
