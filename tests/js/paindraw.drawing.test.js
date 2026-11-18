// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, drawStroke, loadPainDraw, mouse, TEST_PNG, touch } from './helpers.js';

afterEach(cleanup);

/**
 * Canvas is 400 px wide in the configuration. Unless a test says otherwise
 * the harness lays it out at 200 px (rect.width) starting at (10, 20), so the
 * scale factor is 0.5 and a click at client (60, 70) is canvas point (100, 100).
 */
async function loadReady(cfg = {}) {
  const env = await loadPainDraw({ cfg });
  env.rect.left = 10;
  env.rect.top = 20;
  env.rect.width = env.cfg.canvas_width / 2;
  env.loadBackground();
  return env;
}

describe('before the background image has loaded', () => {
  it('ignores the mouse', async () => {
    const env = await loadPainDraw();
    env.rect.width = 200;

    drawStroke(env, [[60, 70], [70, 70]]);

    expect(env.ctx.fillRect).not.toHaveBeenCalled();
    expect(env.strokes()).toEqual([]);
  });
});

describe('spray tool', () => {
  it('records the stroke with its settings and every sampled point', async () => {
    const env = await loadReady();

    drawStroke(env, [[60, 70], [70, 70]]);

    expect(env.strokes()).toEqual([
      {
        strokeId: 1,
        drawInputType: 'mouse',
        tool: 'spray',
        color: '#fc3d03',
        lineWidth: 3,
        sprayDensity: 15,
        sprayRadius: 5,
        alpha: 0.1,
        scaleFactor: 0.5,
        dpr: window.devicePixelRatio,
        p: [[100, 100], [120, 100]],
      },
    ]);
  });

  it('paints sprayDensity squares around each point', async () => {
    vi.spyOn(Math, 'random').mockReturnValue(0.5); // angle = pi, radius = sprayRadius / 2
    const env = await loadReady({ spray_density: 10, spray_radius: 5, line_width: 3 });

    mouse(env.canvas, 'mousedown', 60, 70);

    expect(env.ctx.fillRect).toHaveBeenCalledTimes(10);
    const [x, y, width, height] = env.ctx.fillRect.mock.calls[0];
    expect(x).toBeCloseTo(100 - 2.5 - 1.5); // point + offset - half the square
    expect(y).toBeCloseTo(100 - 1.5);
    expect([width, height]).toEqual([3, 3]);
    expect(env.ctx.fillStyle).toBe('#fc3d03');
    expect(env.ctx.globalAlpha).toBe(0.1);
  });

  it('keeps every spray square within sprayRadius of the point', async () => {
    const env = await loadReady({ spray_density: 200, spray_radius: 8, line_width: 2 });

    mouse(env.canvas, 'mousedown', 60, 70);

    for (const [x, y] of env.ctx.fillRect.mock.calls) {
      const centreX = x + 1;
      const centreY = y + 1;
      expect(Math.hypot(centreX - 100, centreY - 100)).toBeLessThanOrEqual(8 + 1e-9);
    }
  });

  it('does not paint while the mouse moves without a pressed button', async () => {
    const env = await loadReady();

    mouse(env.canvas, 'mousemove', 60, 70);

    expect(env.ctx.fillRect).not.toHaveBeenCalled();
  });
});

describe('pen tool', () => {
  it('draws a continuous line and records the points', async () => {
    const env = await loadReady({ draw_tool: 'pen', line_width: 6, line_color: '#00aaff', alpha: 0.5 });

    drawStroke(env, [[60, 70], [70, 70], [70, 80]]);

    expect(env.ctx.beginPath).toHaveBeenCalledTimes(1);
    expect(env.ctx.moveTo).toHaveBeenCalledWith(100, 100);
    expect(env.ctx.lineTo).toHaveBeenCalledTimes(3);
    expect(env.ctx.lineTo).toHaveBeenLastCalledWith(120, 120);
    expect(env.ctx.closePath).toHaveBeenCalledTimes(1);
    expect(env.ctx.lineWidth).toBe(6);
    expect(env.ctx.strokeStyle).toBe('#00aaff');
    expect(env.ctx.globalAlpha).toBe(0.5);
    expect(env.ctx.fillRect).not.toHaveBeenCalled();

    const [stroke] = env.strokes();
    expect(stroke.tool).toBe('pen');
    expect(stroke.color).toBe('#00aaff');
    expect(stroke.lineWidth).toBe(6);
    expect(stroke.alpha).toBe(0.5);
    expect(stroke.p).toEqual([[100, 100], [120, 100], [120, 120]]);
  });
});

describe('storing the drawing', () => {
  it('writes a PNG of the canvas to the hidden field when a stroke ends', async () => {
    const env = await loadReady();

    drawStroke(env, [[60, 70]]);

    expect(env.pngInput.value).toBe(TEST_PNG);
  });

  it('marks the REDCap form as changed once per finished stroke', async () => {
    const env = await loadReady();

    drawStroke(env, [[60, 70], [70, 70]]);
    expect(globalThis.setDataEntryFormValuesChanged).toHaveBeenCalledTimes(1);

    drawStroke(env, [[60, 70]]);
    expect(globalThis.setDataEntryFormValuesChanged).toHaveBeenCalledTimes(2);
  });

  it('numbers strokes consecutively and keeps all of them', async () => {
    const env = await loadReady();

    drawStroke(env, [[60, 70]]);
    drawStroke(env, [[70, 70], [80, 80]]);
    drawStroke(env, [[90, 90]]);

    const strokes = env.strokes();
    expect(strokes.map((s) => s.strokeId)).toEqual([1, 2, 3]);
    expect(strokes.map((s) => s.p.length)).toEqual([1, 2, 1]);
  });

  it('stores nothing when the mouse is released without a stroke in progress', async () => {
    const env = await loadReady();

    mouse(env.canvas, 'mouseup', 60, 70);

    expect(env.strokes()).toEqual([]);
    expect(globalThis.setDataEntryFormValuesChanged).not.toHaveBeenCalled();
  });
});

describe('coordinates', () => {
  it('records integer canvas coordinates, corrected for CSS scaling', async () => {
    const env = await loadReady();
    env.rect.width = 300; // canvas displayed at 75 %

    // Client coordinates are integers on purpose: browsers truncate fractional
    // clientX/clientY on synthetic events, jsdom does not.
    drawStroke(env, [[57, 42], [100, 100]]);

    const [stroke] = env.strokes();
    expect(stroke.scaleFactor).toBe(0.75);
    // (57 - 10) / 0.75 = 62.67, (42 - 20) / 0.75 = 29.33,
    // (100 - 10) / 0.75 = 120,  (100 - 20) / 0.75 = 106.67
    expect(stroke.p).toEqual([[63, 29], [120, 107]]);
  });

  it('measures the scale again for every stroke, so rotating the device is handled', async () => {
    const env = await loadReady();

    drawStroke(env, [[60, 70]]);
    env.rect.width = 400;
    drawStroke(env, [[60, 70]]);

    const [first, second] = env.strokes();
    expect(first.scaleFactor).toBe(0.5);
    expect(second.scaleFactor).toBe(1);
    expect(second.p).toEqual([[50, 50]]);
  });
});

describe('touch input', () => {
  it('is translated to a stroke marked as touch', async () => {
    const env = await loadReady();

    drawStroke(env, [[60, 70], [70, 70]], 'touch');

    const [stroke] = env.strokes();
    expect(stroke.drawInputType).toBe('touch');
    expect(stroke.p).toEqual([[100, 100], [120, 100]]);
  });

  it('returns to "mouse" for the next mouse stroke', async () => {
    const env = await loadReady();

    drawStroke(env, [[60, 70]], 'touch');
    drawStroke(env, [[60, 70]], 'mouse');

    expect(env.strokes().map((s) => s.drawInputType)).toEqual(['touch', 'mouse']);
  });

  it('paints while the finger moves', async () => {
    const env = await loadReady();

    touch(env.canvas, 'touchstart', 60, 70);
    const afterStart = env.ctx.fillRect.mock.calls.length;
    touch(env.canvas, 'touchmove', 70, 70);

    expect(afterStart).toBe(15);
    expect(env.ctx.fillRect.mock.calls.length).toBe(30);
  });
});

describe('reset button', () => {
  it('discards all strokes and reloads the background', async () => {
    const env = await loadReady();
    drawStroke(env, [[60, 70]]);
    drawStroke(env, [[70, 70]]);

    document.getElementById('pscResetButton').click();

    expect(env.strokesInput.value).toBe('');
    expect(env.pngInput.value).toBe('');
    expect(env.images).toHaveLength(2);
  });

  it('blocks drawing until the background has been reloaded, then restarts numbering', async () => {
    const env = await loadReady();
    drawStroke(env, [[60, 70]]);
    document.getElementById('pscResetButton').click();

    drawStroke(env, [[60, 70]]);
    expect(env.strokes()).toEqual([]);

    env.loadBackground();
    drawStroke(env, [[60, 70]]);
    expect(env.strokes().map((s) => s.strokeId)).toEqual([1]);
  });
});

describe('download button', () => {
  it('offers the canvas as paindraw_copy.png', async () => {
    await loadReady();
    let anchor = null;
    let attachedWhenClicked = false;
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
      anchor = this;
      attachedWhenClicked = document.body.contains(this);
    });

    document.getElementById('pscDlButton').click();

    expect(anchor).not.toBeNull();
    expect(anchor.download).toBe('paindraw_copy.png');
    expect(anchor.getAttribute('href')).toBe(TEST_PNG);
    expect(attachedWhenClicked).toBe(true);
    expect(document.body.contains(anchor)).toBe(false); // cleaned up again
  });
});

describe('releasing the mouse button outside the canvas', () => {
  it('ends and stores the stroke', async () => {
    const env = await loadReady();

    mouse(env.canvas, 'mousedown', 60, 70);
    mouse(env.canvas, 'mousemove', 70, 70);
    document.body.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));

    expect(env.strokes()).toHaveLength(1);
    expect(env.strokes()[0].p).toEqual([[100, 100], [120, 100]]);
    expect(env.pngInput.value).toBe(TEST_PNG);
  });

  it('does not keep painting when the pointer returns to the canvas', async () => {
    const env = await loadReady();
    mouse(env.canvas, 'mousedown', 60, 70);
    document.body.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    const painted = env.ctx.fillRect.mock.calls.length;

    mouse(env.canvas, 'mousemove', 70, 70);

    expect(env.ctx.fillRect.mock.calls.length).toBe(painted);
  });

  it('stores a normal stroke only once, although mouseup also reaches the document', async () => {
    const env = await loadReady();

    mouse(env.canvas, 'mousedown', 60, 70);
    mouse(env.canvas, 'mouseup', 60, 70);

    expect(env.strokes()).toHaveLength(1);
    expect(globalThis.setDataEntryFormValuesChanged).toHaveBeenCalledTimes(1);
  });
});
