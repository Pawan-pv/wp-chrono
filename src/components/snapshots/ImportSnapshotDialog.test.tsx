import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ImportSnapshotDialog } from './ImportSnapshotDialog';

const KB = 1024;

interface Sent {
  url: string;
  offset: number;
  final: string;
  upload: string;
  bytes: number;
}

/**
 * Fake REST API: /snapshots/import/chunk accepts parts up to `bodyLimit`
 * bytes (like nginx client_max_body_size) and answers 413 above it.
 */
function fakeServer({ bodyLimit = Infinity, ticks = 2, tickError }: { bodyLimit?: number; ticks?: number; tickError?: string } = {}) {
  const sent: Sent[] = [];
  let tickCount = 0;
  const fetchMock = vi.fn(async (url: string, init: RequestInit) => {
    const json = (status: number, body: unknown) =>
      new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    if (url.includes('snapshots/import/chunk')) {
      const form = init.body as FormData;
      const chunk = form.get('chunk') as Blob;
      if (chunk.size > bodyLimit) return new Response('<html>413 Request Entity Too Large</html>', { status: 413 });
      sent.push({
        url,
        offset: Number(form.get('offset')),
        final: String(form.get('final')),
        upload: String(form.get('upload')),
        bytes: chunk.size,
      });
      const final = form.get('final') === '1';
      return json(200, { done: false, upload: 'up_1', job: final ? 'job_1' : undefined, message: final ? 'Validated a WP Chrono export' : 'chunk ok' });
    }
    if (url.includes('snapshots/import/tick')) {
      tickCount++;
      if (tickError) return json(422, { code: 'wp_chrono_import_statement', message: tickError });
      const done = tickCount >= ticks;
      return json(200, { done, job: 'job_1', message: done ? 'Imported as snapshot #12' : `tick ${tickCount}` });
    }
    return json(404, { message: 'unexpected ' + url });
  });
  vi.stubGlobal('fetch', fetchMock);
  return { sent, fetchMock, ticks: () => tickCount };
}

const file = (size: number) => new File([new Uint8Array(size)], 'backup.sql.gz', { type: 'application/gzip' });

async function startImport(size: number, maxChunk: number) {
  const onImported = vi.fn();
  const onOpenChange = vi.fn();
  const user = userEvent.setup();
  render(<ImportSnapshotDialog open onOpenChange={onOpenChange} maxChunk={maxChunk} onImported={onImported} />);
  const input = document.querySelector<HTMLInputElement>('[data-e2e="import-file"]')!;
  await user.upload(input, file(size));
  await user.click(screen.getByRole('button', { name: /upload & import/i }));
  return { onImported, onOpenChange, user };
}

describe('ImportSnapshotDialog', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('cannot start before a file is chosen', () => {
    fakeServer();
    render(<ImportSnapshotDialog open onOpenChange={vi.fn()} maxChunk={256 * KB} onImported={vi.fn()} />);
    expect(screen.getByRole('button', { name: /upload & import/i })).toBeDisabled();
  });

  it('uploads in contiguous chunks, marks only the last as final, then imports until done', async () => {
    const server = fakeServer({ ticks: 3 });
    const { onImported, onOpenChange } = await startImport(600 * KB, 256 * KB);

    await waitFor(() => expect(onImported).toHaveBeenCalledWith('Imported as snapshot #12'));
    expect(server.sent.map((s) => [s.offset, s.bytes, s.final])).toEqual([
      [0, 256 * KB, '0'],
      [256 * KB, 256 * KB, '0'],
      [512 * KB, 88 * KB, '1'],
    ]);
    // The upload id from the first answer is sent with every later part.
    expect(server.sent.map((s) => s.upload)).toEqual(['', 'up_1', 'up_1']);
    expect(server.ticks()).toBe(3);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('marks the last part final when the file is an exact multiple of the chunk size', async () => {
    const server = fakeServer();
    const { onImported } = await startImport(512 * KB, 256 * KB);

    await waitFor(() => expect(onImported).toHaveBeenCalled());
    expect(server.sent.map((s) => [s.offset, s.bytes, s.final])).toEqual([
      [0, 256 * KB, '0'],
      [256 * KB, 256 * KB, '1'],
    ]);
  });

  it('halves the chunk and resends the same part when the web server answers 413', async () => {
    const server = fakeServer({ bodyLimit: 100 * KB });
    const { onImported } = await startImport(300 * KB, 512 * KB);

    await waitFor(() => expect(onImported).toHaveBeenCalled());
    // 512 KB (413) → 150 KB (413) → 75 KB parts, without gaps or overlaps.
    expect(server.sent.every((s) => s.bytes <= 100 * KB)).toBe(true);
    let expected = 0;
    for (const s of server.sent) {
      expect(s.offset).toBe(expected);
      expected += s.bytes;
    }
    expect(expected).toBe(300 * KB);
    expect(server.sent.filter((s) => s.final === '1')).toHaveLength(1);
    expect(server.sent[server.sent.length - 1].final).toBe('1');
  });

  it('gives up with a clear message when even the smallest part is too large', async () => {
    fakeServer({ bodyLimit: 10 * KB });
    const { onImported } = await startImport(300 * KB, 512 * KB);

    expect(await screen.findByText(/HTTP 413/)).toBeInTheDocument();
    expect(onImported).not.toHaveBeenCalled();
    // Back on the file picker, ready to retry.
    expect(screen.getByRole('button', { name: /upload & import/i })).toBeEnabled();
  });

  it("shows the server's reason when the dump is refused", async () => {
    fakeServer({ tickError: "The dump's CREATE TABLE for wp_x was refused: storage engine FEDERATED is not allowed" });
    const { onImported } = await startImport(10 * KB, 256 * KB);

    expect(await screen.findByText(/storage engine FEDERATED is not allowed/)).toBeInTheDocument();
    expect(onImported).not.toHaveBeenCalled();
  });

  it('cannot be closed with Escape while the upload runs', async () => {
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        await gate;
        return new Response(JSON.stringify({ done: true, job: 'j', upload: 'u', message: 'done' }), { status: 200 });
      }),
    );
    const { onOpenChange, user } = await startImport(10 * KB, 256 * KB);
    expect(await screen.findByText(/Uploading/)).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(onOpenChange).not.toHaveBeenCalledWith(false);
    release();
    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false));
  });
});
