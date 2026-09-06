Review and ensure everything is optimize and using the best practice and no security issue
use offline like nextjs 
document all features including new features such as withoutjs

Can we generate a full static site if all pages are static?
Update the docs instruction
Document ppr cache at edge

Why page import have RscKit\PageRoute;, can't these be used outside of laravel?
docs is now stale

support view transition when stable in react

support api endpoints

test for route generation

document bun and node usage if differ
there is alot of security vulnerabilty with nextjs and react server functions especially. do we have these issues as well?


With nextjs revalidate refresh the entire page altho look seemless, can we get targetted revalidation. Example if I have two tables on a page and I only affect one table with an action, could we only refresh data for one table instead of the entire page? but someone user might also want to refresh everything


switch to navigation api when become stable

add skills/something like laravel boost
add mcp

announce targetted rerender etc in the doc

Does our client library tree shake etc?

do a final code clean up and optimization to reduce size and increase performance without breaking

can bun do static serving
measure performance

standard to follow web standards

laravel have route generation helper/autocomplete does the js instances have this? 

Can server function do multiple submit at once, I know nextjs sequence them because it kind of difficult to handle multiple but that is next striction and not server funciton.

Also does our form support standard schema validation, I know it was built most for laravel but since we extend scope it should support it


How do I access query string/path variable, document for both laravel and js
i18n translation support

have ai conduct a serious security test on the package

useSearchParams feature
- `rsc-kit init` for an existing Laravel app — the JS hosts have it; Laravel still needs one (install the package, write vite.config, resources/js/rsc, config/rsc.php, the serve command).


support proper end to end testing which currently not supported in next jd
support csp 

seek sponsorship from render, laravel cloud, cloudflare, vercel and netlify 


## Closed 2026-09-05 (rsc-kit 0.1.0/0.1.1 + PPR resume)

- use offline like nextjs — `@rsc-kit/core/useOffline` is now an entry point.
  Next has `useOffline` and no `useOnline`; ours exported the function already
  but never exposed the path.
- laravel route generation helper for the JS side — typed routes ship; the
  build writes `rsc-routes.d.ts` and `Href` constrains Link/visit/prefetch/Form.
  Deliberately no `route()` builder: a template literal is checked the same way.
- Document ppr cache at edge — `guides/edge-caching.mdx`, with the endpoints,
  the worker, and the measured wire shape.
- document withoutjs — `guides/no-javascript.mdx`.
- Can server functions submit concurrently — yes, not sequenced. Two actions
  run at once; `applyRevalidated` leaves whichever *arrived* last on screen.
- Docs stale / update instructions — host pages for Bun, Hono, Elysia, Node and
  Workers; quick start; navigation; headers and cookies; landing copy rewritten.

## Open, from the same stretch

**PPR resume**
- Measure against an origin the edge is actually far from. The deployed test
  used a Worker origin, which is already colo-resident — the edge worker
  measured *slower* (58.3 ms vs 55.1 ms) and that is expected, not a bug. The
  case it exists for is a VPS or a Laravel origin in one region.
- Laravel host does not resume yet. `handleRscResume` is implemented in the JS
  engine and wired into `host.ts`; the PHP bridge has no equivalent, so a
  Laravel-served shell is still filled by the client.
- The response now stays open until the slowest boundary resolves, so `load`
  fires later than it used to. Check anything keyed on it, and any proxy with a
  short response timeout.

**Docs still missing a page**
- sections / named regions (`@rsc-kit/core/section`)
- `revalidate`, and targeted revalidation once it exists
- `cache()` — the per-request memo
- offline behaviour end to end, now that `useOffline` is exposed
- deployment for the JS hosts; only Laravel has a deployment page

**Vercel**
- Runs as a Vercel Function today, but their edge implements *Next's* PPR
  protocol and will not resume our shells. A real adapter means emitting their
  Build Output API. Only worth it if Vercel should be a first-class target.

**Proxy coverage (from the next-bun-compile incident)**
- An auth proxy that re-issues a session on pass-through owns a response head
  this host does not know about. Covered paths need a `middleware.ts` so they
  are excluded from every cache decision. Documented in `edge-caching.mdx`;
  worth a line wherever deployment behind a proxy is described.


support mcp and skills      


I get a min flash for adding items to optimistic page after the server response return
Action revalidate should be more smoother
can prefetch form actually pass the data to prefetch route to resolve instantly?