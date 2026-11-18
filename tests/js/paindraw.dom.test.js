// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, loadPainDraw } from './helpers.js';

afterEach(cleanup);

describe('canvas construction', () => {
  it('replaces the file upload widget with a canvas of the configured size', async () => {
    const env = await loadPainDraw({ cfg: { canvas_width: 640, canvas_height: 480 } });

    expect(env.canvas).not.toBeNull();
    expect(env.canvas.width).toBe(640);
    expect(env.canvas.height).toBe(480);
    expect(env.canvas.classList.contains('paindraw-canvas')).toBe(true);
    expect(document.querySelector('#pain_draw-tr #drawingCanvas')).not.toBeNull();
    expect(document.querySelector('.sig-imgp')).toBeNull();
  });

  it('keeps the field label', async () => {
    await loadPainDraw();

    expect(document.getElementById('pain_draw-tr').textContent).toContain('Draw your pain');
  });

  it('leaves other fields alone', async () => {
    await loadPainDraw();

    expect(document.getElementById('other_field-tr').textContent).toContain('Other field');
  });

  it('adds reset and download buttons with translated labels', async () => {
    await loadPainDraw();

    const reset = document.getElementById('pscResetButton');
    const download = document.getElementById('pscDlButton');
    expect(reset.textContent).toBe('Reset');
    expect(download.textContent).toBe('Download copy');
    expect(reset.getAttribute('type')).toBe('button');
    expect(download.getAttribute('type')).toBe('button');
    expect(globalThis.paindraw.tt).toHaveBeenCalledWith('reset_btn');
    expect(globalThis.paindraw.tt).toHaveBeenCalledWith('download_btn');
  });

  it('adds hidden form fields that carry the strokes and the PNG to the server', async () => {
    const env = await loadPainDraw();

    for (const [input, name] of [
      [env.strokesInput, 'paindraw_stored_strokes'],
      [env.pngInput, 'paindraw_stored_png'],
    ]) {
      expect(input).not.toBeNull();
      expect(input.getAttribute('name')).toBe(name);
      expect(input.getAttribute('type')).toBe('hidden');
      expect(input.closest('#form')).not.toBeNull();
    }
  });

  it('injects the responsive canvas stylesheet', async () => {
    await loadPainDraw();

    const css = document.head.innerHTML;
    expect(css).toContain('.paindraw-canvas');
    expect(css).toContain('touch-action: none');
    expect(css).toContain('max-width: 100%');
  });

  it('starts loading the configured background image', async () => {
    const env = await loadPainDraw({ cfg: { img_url: '/some/image.png' } });

    expect(env.images).toHaveLength(1);
    expect(env.images[0].src).toBe('/some/image.png');
  });

  it('paints the background scaled to the canvas once the image has loaded', async () => {
    const env = await loadPainDraw({ cfg: { canvas_width: 400, canvas_height: 300 } });

    env.loadBackground();

    expect(env.ctx.clearRect).toHaveBeenCalledWith(0, 0, 400, 300);
    expect(env.ctx.drawImage).toHaveBeenCalledWith(env.images[0], 0, 0, 400, 300);
  });

  it('warns the user and continues with a blank canvas when the image cannot be loaded', async () => {
    const env = await loadPainDraw();

    env.failBackground();

    expect(globalThis.alert).toHaveBeenCalledTimes(1);
    expect(globalThis.alert.mock.calls[0][0]).toContain('Failed to load background image');
  });
});

describe('when the form has no canvas field', () => {
  it('does nothing', async () => {
    const env = await loadPainDraw({
      html: '<form id="form"><table><tr id="unrelated-tr"><td>x</td></tr></table></form>',
    });

    expect(env.canvas).toBeNull();
    expect(env.strokesInput).toBeNull();
    expect(env.pngInput).toBeNull();
    expect(document.getElementById('unrelated-tr').textContent).toBe('x');
  });
});

describe('when the background image has an unsupported format (img_url is false)', () => {
  it('shows a configuration error on the data entry form', async () => {
    const env = await loadPainDraw({ cfg: { img_url: false, survey: false } });

    const message = document.querySelector('#pain_draw-tr .sig-imgp');
    expect(message).not.toBeNull();
    expect(message.classList.contains('red')).toBe(true);
    expect(message.textContent).toContain('PainDraw configuration error');
    expect(message.textContent).toContain('unsupported file format');
    expect(env.canvas).toBeNull();
    expect(env.strokesInput).toBeNull();
  });

  it('shows nothing at all to survey participants', async () => {
    const env = await loadPainDraw({ cfg: { img_url: false, survey: true } });

    expect(document.getElementById('pain_draw-tr').children).toHaveLength(0);
    expect(env.canvas).toBeNull();
    expect(env.strokesInput).toBeNull();
  });
});

