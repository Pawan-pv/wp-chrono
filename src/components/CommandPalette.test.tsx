import { fireEvent, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CommandPalette } from './CommandPalette';

// WordPress 7+ opens its own admin command palette on Ctrl/Cmd+K from a
// document listener. WP Chrono claims the shortcut on window in the capture
// phase so only one palette opens on its screen.
describe('CommandPalette keyboard shortcut', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ snapshots: [] }), { status: 200 })));
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  const setup = (open = false) => {
    const onOpenChange = vi.fn();
    const coreShortcut = vi.fn();
    document.addEventListener('keydown', coreShortcut);
    const view = render(
      <CommandPalette open={open} onOpenChange={onOpenChange} onNavigate={vi.fn()} onToggleTheme={vi.fn()} onOpenSettings={vi.fn()} />,
    );
    return { onOpenChange, coreShortcut, view, cleanup: () => document.removeEventListener('keydown', coreShortcut) };
  };

  it.each([
    ['Ctrl+K', { ctrlKey: true }],
    ['Cmd+K', { metaKey: true }],
    ['Ctrl+Shift+K (upper-case key)', { ctrlKey: true, shiftKey: true, key: 'K' }],
  ])('%s toggles the palette and never reaches the WordPress core palette', (_label, mods) => {
    const { onOpenChange, coreShortcut, cleanup } = setup(false);
    const event = new KeyboardEvent('keydown', { key: 'k', bubbles: true, cancelable: true, ...mods });
    document.body.dispatchEvent(event);
    expect(onOpenChange).toHaveBeenCalledWith(true);
    expect(coreShortcut).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(true);
    cleanup();
  });

  it('closes when already open', () => {
    const { onOpenChange, cleanup } = setup(true);
    fireEvent.keyDown(document.body, { key: 'k', ctrlKey: true });
    expect(onOpenChange).toHaveBeenCalledWith(false);
    cleanup();
  });

  it('leaves other keys alone', () => {
    const { onOpenChange, coreShortcut, cleanup } = setup(false);
    fireEvent.keyDown(document.body, { key: 'k' });
    fireEvent.keyDown(document.body, { key: 'j', ctrlKey: true });
    expect(onOpenChange).not.toHaveBeenCalled();
    expect(coreShortcut).toHaveBeenCalledTimes(2);
    cleanup();
  });

  it('removes its listener on unmount', () => {
    const { onOpenChange, coreShortcut, view, cleanup } = setup(false);
    view.unmount();
    fireEvent.keyDown(document.body, { key: 'k', ctrlKey: true });
    expect(onOpenChange).not.toHaveBeenCalled();
    expect(coreShortcut).toHaveBeenCalledTimes(1);
    cleanup();
  });
});
