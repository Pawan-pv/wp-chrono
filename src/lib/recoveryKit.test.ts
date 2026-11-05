import { describe, expect, it, vi } from 'vitest';

import { downloadRecoveryKit } from './recoveryKit';

describe('downloadRecoveryKit', () => {
  it('creates a download link and clicks it without throwing', () => {
    const createObjectURL = vi.fn(() => 'blob:mock');
    const revokeObjectURL = vi.fn();
    (URL as any).createObjectURL = createObjectURL;
    (URL as any).revokeObjectURL = revokeObjectURL;

    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    expect(() => downloadRecoveryKit('https://example.com/recover', 'secret123')).not.toThrow();
    expect(createObjectURL).toHaveBeenCalled();
    expect(clickSpy).toHaveBeenCalled();

    clickSpy.mockRestore();
  });
});