// @vitest-environment jsdom
/**
 * JavaScript half of the contract between paindraw.js and the server.
 *
 * tests/fixtures/stroke-contract.json and tests/fixtures/strokes/*.json are
 * shared with the PHP tests (tests/php/ContractTest.php). The contract says
 * what PainDraw::validateJsonInput() accepts; these tests check that
 * paindraw.js only ever produces strokes that satisfy it.
 */
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, drawStroke, listFixtures, loadPainDraw, readFixture } from './helpers.js';

afterEach(cleanup);

const contract = readFixture('stroke-contract.json');

/** Returns a list of human-readable contract violations for one stroke. */
function checkStroke(stroke) {
  const errors = [];

  for (const [key, rule] of Object.entries(contract.rules)) {
    const present = Object.prototype.hasOwnProperty.call(stroke, key) && stroke[key] !== null;
    const required = !rule.requiredWhen
      || Object.entries(rule.requiredWhen).every(([other, value]) => stroke[other] === value);

    if (!present) {
      if (required) errors.push(`${key}: missing`);
      continue;
    }

    const value = stroke[key];
    if (rule.enum && !rule.enum.includes(value)) errors.push(`${key}: not one of ${rule.enum}`);
    if (rule.pattern && !(typeof value === 'string' && new RegExp(rule.pattern).test(value))) errors.push(`${key}: does not match ${rule.pattern}`);
    if (rule.type === 'integer' && !Number.isInteger(value)) errors.push(`${key}: not an integer`);
    if (rule.type === 'number' && typeof value !== 'number') errors.push(`${key}: not a number`);
    if (typeof value === 'number') {
      if (rule.min !== undefined && value < rule.min) errors.push(`${key}: below ${rule.min}`);
      if (rule.max !== undefined && value > rule.max) errors.push(`${key}: above ${rule.max}`);
      if (rule.exclusiveMin !== undefined && value <= rule.exclusiveMin) errors.push(`${key}: not above ${rule.exclusiveMin}`);
      if (rule.maxDecimals !== undefined && (String(value).split('.')[1] ?? '').length > rule.maxDecimals) {
        errors.push(`${key}: more than ${rule.maxDecimals} decimals`);
      }
    }
    if (rule.type === 'points') {
      const valid = Array.isArray(value)
        && value.length >= rule.minItems
        && value.every((pair) => Array.isArray(pair) && pair.length === 2 && pair.every(Number.isInteger));
      if (!valid) errors.push(`${key}: not a list of integer [x, y] pairs`);
    }
  }

  return errors;
}

describe('the contract checker itself', () => {
  it.each(listFixtures('strokes', 'valid_'))('accepts the shared fixture %s', (name) => {
    for (const stroke of readFixture(`strokes/${name}`)) {
      expect(checkStroke(stroke)).toEqual([]);
    }
  });

  it.each(readFixture('strokes/invalid_strokes.json').map((c) => [c.name, c.strokes]))(
    'rejects the shared invalid fixture "%s"',
    (_name, strokes) => {
      const errors = strokes.flatMap(checkStroke);
      expect(errors.length).toBeGreaterThan(0);
    },
  );
});

describe('strokes produced by paindraw.js', () => {
  const scenarios = [
    { tool: 'spray', via: 'mouse' },
    { tool: 'spray', via: 'touch' },
    { tool: 'pen', via: 'mouse' },
    { tool: 'pen', via: 'touch' },
  ];

  it.each(scenarios)('satisfy the contract for $tool via $via', async ({ tool, via }) => {
    const env = await loadPainDraw({ cfg: { draw_tool: tool } });
    env.rect.width = 200;
    env.loadBackground();

    drawStroke(env, [[60, 70], [70, 75], [80, 90]], via);

    const strokes = env.strokes();
    expect(strokes).toHaveLength(1);
    expect(checkStroke(strokes[0])).toEqual([]);
    expect(Object.keys(strokes[0]).sort()).toEqual([...contract.strokeKeys].sort());
  });

  it('satisfy the contract with unusual but valid settings', async () => {
    const env = await loadPainDraw({
      cfg: { line_color: '#00AAff', alpha: 0.07, line_width: 12, spray_density: 40, spray_radius: 20 },
    });
    env.rect.width = 333;
    env.loadBackground();

    drawStroke(env, [[10, 20], [11, 21]]);

    expect(checkStroke(env.strokes()[0])).toEqual([]);
  });
});
