import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { HoldToConfirmButton } from './HoldToConfirmButton';

// The destructive-reset button: an accidental click must never fire it.
describe('HoldToConfirmButton', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['requestAnimationFrame', 'cancelAnimationFrame', 'performance', 'setTimeout'] });
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  const setup = (props: Partial<Parameters<typeof HoldToConfirmButton>[0]> = {}) => {
    const onConfirm = vi.fn();
    render(
      <HoldToConfirmButton armed={false} holdMs={1500} onConfirm={onConfirm} {...props}>
        Reset
      </HoldToConfirmButton>,
    );
    return { onConfirm, button: screen.getByRole('button', { name: 'Reset' }) };
  };

  it('ignores a plain click while not armed', () => {
    const { onConfirm, button } = setup();
    fireEvent.click(button);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('confirms on a plain click once armed (confirm word typed)', () => {
    const { onConfirm, button } = setup({ armed: true });
    fireEvent.click(button);
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('confirms exactly once after holding for holdMs', () => {
    const { onConfirm, button } = setup();
    fireEvent.pointerDown(button);
    act(() => void vi.advanceTimersByTime(1400));
    expect(onConfirm).not.toHaveBeenCalled();
    act(() => void vi.advanceTimersByTime(300));
    expect(onConfirm).toHaveBeenCalledTimes(1);
    act(() => void vi.advanceTimersByTime(3000));
    fireEvent.pointerUp(button);
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it.each(['pointerUp', 'pointerLeave'] as const)('does not confirm when released early (%s)', (release) => {
    const { onConfirm, button } = setup();
    fireEvent.pointerDown(button);
    act(() => void vi.advanceTimersByTime(1000));
    fireEvent[release](button);
    act(() => void vi.advanceTimersByTime(3000));
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('restarts the hold from zero after an early release', () => {
    const { onConfirm, button } = setup();
    fireEvent.pointerDown(button);
    act(() => void vi.advanceTimersByTime(1000));
    fireEvent.pointerUp(button);
    fireEvent.pointerDown(button);
    act(() => void vi.advanceTimersByTime(1000));
    expect(onConfirm).not.toHaveBeenCalled();
    act(() => void vi.advanceTimersByTime(600));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('can be held with the keyboard (Space / Enter)', () => {
    const { onConfirm, button } = setup();
    fireEvent.keyDown(button, { key: 'Enter' });
    act(() => void vi.advanceTimersByTime(1600));
    expect(onConfirm).toHaveBeenCalledTimes(1);

    fireEvent.keyDown(button, { key: ' ' });
    act(() => void vi.advanceTimersByTime(500));
    fireEvent.keyUp(button, { key: ' ' });
    act(() => void vi.advanceTimersByTime(2000));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('never confirms while disabled', () => {
    const { onConfirm, button } = setup({ disabled: true, armed: true });
    expect(button).toBeDisabled();
    fireEvent.click(button);
    fireEvent.pointerDown(button);
    act(() => void vi.advanceTimersByTime(3000));
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('never confirms after it was unmounted mid-hold (dialog closed)', () => {
    const onConfirm = vi.fn();
    const { unmount } = render(
      <HoldToConfirmButton armed={false} holdMs={1500} onConfirm={onConfirm}>
        Reset
      </HoldToConfirmButton>,
    );
    fireEvent.pointerDown(screen.getByRole('button', { name: 'Reset' }));
    act(() => void vi.advanceTimersByTime(500));
    unmount();
    act(() => void vi.advanceTimersByTime(3000));
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
