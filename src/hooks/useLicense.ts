import { useEffect, useState } from 'react';
import { config } from '@/lib/config';
import type { LicenseStatus } from '@/types';

const EVENT = 'wpchrono:license';

/** Broadcasts a new license state (after activate / deactivate) to every view. */
export function publishLicense(status: LicenseStatus) {
  config.license = { active: status.active, plan: status.plan, features: status.features };
  window.dispatchEvent(new CustomEvent(EVENT));
}

/** Current plan + unlocked Pro features; starts from the bootstrap payload, updates live. */
export function useLicense() {
  const [license, setLicense] = useState(config.license);
  useEffect(() => {
    const onChange = () => setLicense({ ...config.license });
    window.addEventListener(EVENT, onChange);
    return () => window.removeEventListener(EVENT, onChange);
  }, []);
  return license;
}
