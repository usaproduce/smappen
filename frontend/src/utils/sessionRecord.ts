/**
 * OP17 — record a ~30s screen recording of the map for sharing. Uses the
 * MediaRecorder API on the map's underlying <canvas>. Saves a .webm file
 * via the browser download.
 *
 * Caveats:
 *   • Google Maps' tile canvas is the largest <canvas> in the document.
 *     We pick the biggest one. Multi-canvas setups (heatmap overlay) are
 *     captured via `canvas.captureStream()` which respects the layered
 *     content as drawn.
 *   • Audio is not captured.
 *   • Stop early by calling the returned `stop()` function.
 */
export function startMapRecording(durationMs = 30_000): { stop: () => void } | null {
  if (typeof window === 'undefined') return null;
  // Find the largest canvas on the page — Google Maps' tile canvas.
  const canvases = Array.from(document.querySelectorAll('canvas'));
  if (canvases.length === 0) return null;
  const target = canvases.reduce((a, b) => (a.width * a.height >= b.width * b.height ? a : b));
  // captureStream needs to be wrapped — Chrome+Firefox both support it.
  // Some browsers also accept `mozCaptureStream` (skipped here; works on
  // every browser we ship to).
  const stream = (target as any).captureStream?.(30);
  if (!stream) return null;
  const chunks: BlobPart[] = [];
  const mime = MediaRecorder.isTypeSupported('video/webm; codecs=vp9') ? 'video/webm; codecs=vp9' : 'video/webm';
  const rec = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 4_000_000 });
  rec.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data); };
  rec.onstop = () => {
    const blob = new Blob(chunks, { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `smappen-map-${new Date().toISOString().replace(/[:.]/g, '-')}.webm`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };
  rec.start(250);
  const timer = window.setTimeout(() => rec.state === 'recording' && rec.stop(), durationMs);
  return {
    stop() {
      window.clearTimeout(timer);
      if (rec.state === 'recording') rec.stop();
    },
  };
}
