import { useCallback, useEffect, useState } from 'react';

export const ROUTES = ['dashboard', 'reset', 'snapshots', 'recovery', 'blueprints'] as const;
export type Route = (typeof ROUTES)[number];
export type RouteParams = Record<string, string>;

interface Location {
  route: Route;
  params: RouteParams;
}

function read(): Location {
  const [path, query = ''] = window.location.hash.replace(/^#\/?/, '').split('?');
  const route = (ROUTES as readonly string[]).includes(path) ? (path as Route) : 'dashboard';
  return { route, params: Object.fromEntries(new URLSearchParams(query)) };
}

/**
 * Hash-based routing keeps us inside admin.php?page=wp-chrono without server
 * rewrites. Params (e.g. `#/reset?run=transients`) carry one-shot intents from
 * the command palette; views consume them with `clearParams()`.
 */
export function useHashRoute() {
  const [location, setLocation] = useState<Location>(read);

  useEffect(() => {
    const onChange = () => setLocation(read());
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);

  const navigate = useCallback((next: Route, params?: RouteParams) => {
    const query = params ? `?${new URLSearchParams(params)}` : '';
    window.location.hash = `/${next}${query}`;
  }, []);

  /** Drops the query from the URL without firing hashchange. */
  const clearParams = useCallback(() => {
    const { route } = read();
    window.history.replaceState(null, '', `#/${route}`);
  }, []);

  return { route: location.route, params: location.params, navigate, clearParams };
}
