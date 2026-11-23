/**
 * Test harness for js/paindraw.js.
 *
 * paindraw.js is a classic script: it has no exports and expects REDCap to
 * have defined the globals `paindraw_cfg` (printed by PainDraw.php) and
 * `paindraw` (the module's JavaScript object). The harness builds a REDCap-like
 * data entry form, defines those globals, replaces the browser features jsdom
 * does not implement (canvas 2D context, image loading, layout) and then runs
 * the real script.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { vi } from 'vitest';
import $ from 'jquery';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const SCRIPT_SOURCE = fs.readFileSync(path.join(ROOT, 'js/paindraw.js'), 'utf8');

export const TEST_PNG = 'data:image/png;base64,TESTPNG';

/** What the mocked canvas returns from getImageData(), so tests can see it being put back. */
export const SNAPSHOT = { snapshot: true };

const TRANSLATIONS = { reset_btn: 'Reset', download_btn: 'Download copy' };

/** Settings as PainDraw.php::parseModuleSettings() would hand them to the script. */
export const DEFAULT_CFG = Object.freeze({
  canvas_field: 'pain_draw',
  img_url: '/redcap/DataEntry/image_view.php?doc_id_hash=abc&pid=7&id=42',
  survey: false,
  canvas_width: 400,
  canvas_height: 300,
  spray_radius: 5,
  spray_density: 15,
  line_width: 3,
  line_color: '#fc3d03',
  draw_tool: 'spray',
  alpha: 0.1,
});

/** The relevant part of a REDCap data entry form. */
export const FORM_HTML = `
  <form id="form">
    <table>
      <tr id="pain_draw-tr">
        <td class="labelrc">Draw your pain</td>
        <td class="data"><div class="sig-imgp">file upload widget</div></td>
      </tr>
      <tr id="other_field-tr"><td class="labelrc">Other field</td></tr>
    </table>
  </form>`;

export function readFixture(relativePath) {
  return JSON.parse(fs.readFileSync(path.join(ROOT, 'tests/fixtures', relativePath), 'utf8'));
}

export function listFixtures(subdirectory, prefix) {
  return fs
    .readdirSync(path.join(ROOT, 'tests/fixtures', subdirectory))
    .filter((name) => name.startsWith(prefix) && name.endsWith('.json'))
    .sort();
}

function makeContextMock() {
  return {
    globalAlpha: 1,
    fillStyle: '',
    strokeStyle: '',
    lineWidth: 1,
    lineCap: '',
    lineJoin: '',
    clearRect: vi.fn(),
    drawImage: vi.fn(),
    getImageData: vi.fn(() => SNAPSHOT),
    putImageData: vi.fn(),
    fillRect: vi.fn(),
    beginPath: vi.fn(),
    moveTo: vi.fn(),
    lineTo: vi.fn(),
    stroke: vi.fn(),
    closePath: vi.fn(),
  };
}

/**
 * Build the page and run paindraw.js.
 *
 * @param {object} [options]
 * @param {object} [options.cfg]   overrides for DEFAULT_CFG
 * @param {string} [options.html]  replacement form markup
 */
export async function loadPainDraw({ cfg = {}, html = FORM_HTML } = {}) {
  const fullCfg = { ...DEFAULT_CFG, ...cfg };
  document.body.innerHTML = html;

  const images = [];
  class FakeImage {
    constructor() {
      images.push(this);
    }
  }

  const ctx = makeContextMock();
  // CSS size of the canvas on screen; tests change it to simulate responsive layouts.
  const rect = { left: 0, top: 0, width: fullCfg.canvas_width, height: fullCfg.canvas_height };

  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => ctx);
  vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockImplementation(() => TEST_PNG);
  vi.spyOn(HTMLCanvasElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({
    ...rect,
    x: rect.left,
    y: rect.top,
    right: rect.left + rect.width,
    bottom: rect.top + rect.height,
  }));

  vi.stubGlobal('$', $);
  vi.stubGlobal('jQuery', $);
  vi.stubGlobal('Image', FakeImage);
  vi.stubGlobal('alert', vi.fn());
  vi.stubGlobal('setDataEntryFormValuesChanged', vi.fn());
  vi.stubGlobal('paindraw', { tt: vi.fn((key) => TRANSLATIONS[key] ?? key) });
  vi.stubGlobal('paindraw_cfg', fullCfg);

  // Indirect eval = run as a classic global script, like a <script src> tag.
  (0, eval)(SCRIPT_SOURCE);
  // jQuery calls $(document).ready() handlers asynchronously.
  await new Promise((resolve) => setTimeout(resolve, 25));

  return {
    cfg: fullCfg,
    ctx,
    images,
    rect,
    canvas: document.getElementById('drawingCanvas'),
    strokesInput: document.getElementById('paindraw_stored_strokes'),
    pngInput: document.getElementById('paindraw_stored_png'),
    /** Simulate the background image finishing to load. */
    loadBackground() {
      images.at(-1).onload();
    },
    /** Simulate the background image failing to load. */
    failBackground() {
      images.at(-1).onerror();
    },
    /** The strokes currently stored in the hidden form field. */
    strokes() {
      const value = this.strokesInput?.value ?? '';
      return value ? JSON.parse(value) : [];
    },
  };
}

/** Undo everything loadPainDraw() set up. Call from afterEach(). */
export function cleanup() {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
  document.head.querySelectorAll('style').forEach((style) => style.remove());
}

export function mouse(target, type, clientX = 0, clientY = 0) {
  target.dispatchEvent(new MouseEvent(type, { clientX, clientY, bubbles: true }));
}

export function touch(target, type, clientX = 0, clientY = 0) {
  const event = new Event(type, { bubbles: true });
  Object.defineProperty(event, 'touches', { value: [{ clientX, clientY }] });
  target.dispatchEvent(event);
}

/**
 * Draw one stroke through the given client-space points.
 *
 * @param {object} env     result of loadPainDraw()
 * @param {number[][]} points
 * @param {'mouse'|'touch'} [via]
 */
export function drawStroke(env, points, via = 'mouse') {
  const send = via === 'touch' ? touch : mouse;
  const names = via === 'touch'
    ? { down: 'touchstart', move: 'touchmove', up: 'touchend' }
    : { down: 'mousedown', move: 'mousemove', up: 'mouseup' };

  const [first, ...rest] = points;
  send(env.canvas, names.down, first[0], first[1]);
  rest.forEach(([x, y]) => send(env.canvas, names.move, x, y));
  send(env.canvas, names.up, 0, 0);
}
