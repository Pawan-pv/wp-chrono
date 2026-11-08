export interface ChronoConfig {
  version: string;
  restUrl: string;
  restNonce: string;
  chronoNonce: string;
  adminUrl: string;
  siteUrl: string;
  siteName: string;
  user: { name: string; login: string };
  woocommerce: boolean;
  multisite: boolean;
  /** Multisite main site (network tables can be included in restores). */
  mainSite: boolean;
  superAdmin: boolean;
  /** First run after activation: show the welcome modal. */
  welcome: boolean;
  /** pricing: the store's plans page, '' when no API is configured. */
  license: { active: boolean; plan: string; features: Record<'blueprints' | 'cloud', boolean>; pricing: string };
}

declare global {
  interface Window {
    wpChrono?: ChronoConfig;
  }
}

/** Injected by Admin::enqueue() via wp_add_inline_script. */
export const config: ChronoConfig = window.wpChrono ?? {
  version: 'dev',
  // Same index.php?rest_route= form Admin gives, so the fallback works
  // with any permalink setting too.
  restUrl: '/index.php?rest_route=/wp-chrono/v1/',
  restNonce: '',
  chronoNonce: '',
  adminUrl: '/wp-admin/',
  siteUrl: '/',
  siteName: 'WordPress',
  user: { name: 'Admin', login: 'admin' },
  woocommerce: false,
  multisite: false,
  mainSite: false,
  superAdmin: false,
  welcome: false,
  license: { active: false, plan: 'free', features: { blueprints: false, cloud: false }, pricing: '' },
};
