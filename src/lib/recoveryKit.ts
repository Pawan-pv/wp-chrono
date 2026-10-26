import { config } from './config';

/**
 * Saves a plain-text "recovery kit" (secret URL + one-time password + what to
 * do when the site is down) to the user's computer, so they have it before
 * the site ever crashes. Generated in the browser; nothing is sent anywhere.
 */
export function downloadRecoveryKit(url: string, password: string): void {
  const text = [
    `WP Chrono — Emergency Recovery Kit`,
    `Site:      ${config.siteName} (${config.siteUrl})`,
    `Generated: ${new Date().toISOString()}`,
    '',
    `Recovery URL:  ${url}`,
    `Password:      ${password}`,
    '',
    'Keep this file somewhere safe and private (a password manager is ideal).',
    'Anyone with both the URL and the password can repair — or roll back — this site.',
    '',
    'WHEN THE SITE IS DOWN (white screen, "critical error", locked out of wp-admin):',
    '  1. Open the recovery URL above in any browser. It works even when WordPress does not.',
    '  2. Enter the password.',
    '  3. "Crash diagnostics" names the plugin or theme that caused the fatal error.',
    '  4. Fix it with one click:',
    '       - "Undo Last Update (Files + DB)" if a plugin/theme update broke the site,',
    '       - deactivate the crashing plugin, or switch to a default theme,',
    '       - or restore a snapshot (atomic: the site is never half-restored).',
    '  5. Reload your site, then delete the recovery script from the console.',
    '',
    'Regenerating the script in WP Chrono → Recovery creates a new URL and password',
    'and invalidates this kit.',
    '',
  ].join('\n');
  const href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
  const a = Object.assign(document.createElement('a'), {
    href,
    download: `wp-chrono-recovery-kit-${new URL(config.siteUrl, location.href).hostname}.txt`,
  });
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 1000);
}
