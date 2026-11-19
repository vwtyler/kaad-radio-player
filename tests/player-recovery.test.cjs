const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const php = fs.readFileSync('kaad-radio-player.php', 'utf8');
const match = php.match(/<script>\s*([\s\S]*?)<\/script>/);
assert.ok(match, 'player script must exist');
const script = match[1]
  .replace('<?php echo json_encode($uid); ?>', '"test-player"')
  .replace('<?php echo json_encode($proxy); ?>', '"/test-metadata"')
  .replace('<?php echo json_encode($refresh * 1000); ?>', '20000');

function setup() {
  const timers = new Map();
  let clock = 0;
  let nextTimer = 1;
  function setTimeoutFake(fn, ms) {
    const id = nextTimer++;
    timers.set(id, { when: clock + ms, fn });
    return id;
  }
  function clearTimeoutFake(id) { timers.delete(id); }
  async function advance(ms) {
    const target = clock + ms;
    while (true) {
      const due = [...timers.entries()]
        .filter(([, t]) => t.when <= target)
        .sort((a, b) => a[1].when - b[1].when)[0];
      if (!due) break;
      clock = due[1].when;
      timers.delete(due[0]);
      due[1].fn();
      await flush();
    }
    clock = target;
  }
  const listeners = {};
  function emit(name) {
    (listeners[name] || []).forEach(fn => fn({ stopPropagation() {} }));
  }
  function el() {
    const own = {};
    return {
      innerHTML: '', textContent: '', value: '1', volume: 1,
      style: { setProperty() {}, removeProperty() {} },
      classList: { add(x) { own[x] = true; }, remove(x) { delete own[x]; },
        toggle(x, force) { if (force) own[x] = true; else delete own[x]; } },
      setAttribute(k, v) { own[k] = String(v); },
      getAttribute(k) { return own[k] || null; },
      addEventListener(name, fn) { (listeners[this.kind + ':' + name] ||= []).push(fn); },
      _emit(name) { (listeners[this.kind + ':' + name] || []).forEach(fn => fn({ stopPropagation() {} })); }
    };
  }
  const audio = el(); audio.kind = 'audio'; audio.paused = true; audio.ended = false;
  audio.readyState = 0; audio.autoplay = false; audio.loadCount = 0; audio.playCount = 0;
  audio.pause = () => { audio.paused = true; audio._emit('pause'); };
  audio.load = () => { audio.loadCount++; audio.readyState = 0; };
  audio.play = async () => {
    audio.playCount++;
    audio.paused = false;
    audio._emit('play');
    audio.readyState = 4;
    audio._emit('playing');
  };
  const playBtn = el(); playBtn.kind = 'playBtn';
  const playbackStatus = el(); playbackStatus.kind = 'status';
  const np = el(); np.kind = 'np';
  const volume = el(); volume.kind = 'volume';
  const speaker = el(); speaker.kind = 'speaker';
  const root = { querySelector: name => ({
    '.kaad-audio': audio, '.kaad-play': playBtn,
    '.kaad-volume': volume, '.kaad-speaker': speaker,
    '.kaad-now-playing': np, '.kaad-playback-status': playbackStatus
  })[name] };
  const warnings = [];
  vm.runInNewContext(script, {
    document: { getElementById: () => root },
    window: { matchMedia: () => ({matches: false}) },
    HTMLMediaElement: { HAVE_FUTURE_DATA: 3 },
    setTimeout: setTimeoutFake, clearTimeout: clearTimeoutFake,
    setInterval: () => 999, clearInterval: () => {},
    requestAnimationFrame: () => {},
    fetch: async () => ({ok:true,json:async () => ({text:''})}),
    console: {warn: (...args) => warnings.push(args)}
  });
  return { audio, playBtn, status: playbackStatus, advance, warnings };
}
async function flush() { for (let i=0;i<4;i++) await Promise.resolve(); }

(async () => {
  // Starting after the page has been idle must discard buffered audio.
  {
    const x = setup();
    await x.advance(180000);
    assert.equal(x.audio.loadCount, 0);
    x.playBtn._emit('click'); await flush();
    assert.equal(x.audio.loadCount, 1);
    assert.equal(x.audio.playCount, 1);
    x.playBtn._emit('click');
    x.playBtn._emit('click'); await flush();
    assert.equal(x.audio.loadCount, 2);
    assert.equal(x.audio.playCount, 2);
  }
  // An explicit stop should never trigger reconnection.
  {
    const x = setup();
    x.playBtn._emit('click'); await flush();
    assert.equal(x.audio.playCount, 1);
    x.playBtn._emit('click');
    await x.advance(60000);
    assert.equal(x.audio.playCount, 1);
    assert.equal(x.status.textContent, '');
  }
  // Media errors reconnect after a delay, without overwriting track metadata.
  {
    const x = setup();
    x.playBtn._emit('click'); await flush();
    x.audio.error = {code:2};
    x.audio._emit('error');
    assert.match(x.status.textContent, /Retrying in 2s/);
    await x.advance(1999);
    assert.equal(x.audio.playCount, 1);
    await x.advance(1);
    assert.equal(x.audio.playCount, 2);
    assert.equal(x.audio.loadCount, 2);
    assert.equal(x.status.textContent, '');
  }
  // A persistent stall triggers a reconnect even if readyState remains stale.
  {
    const x = setup();
    x.playBtn._emit('click'); await flush();
    x.audio.readyState = 4;
    x.audio._emit('stalled');
    await x.advance(15000);
    assert.match(x.status.textContent, /Retrying in 2s/);
    await x.advance(2000);
    assert.equal(x.audio.playCount, 2);
  }
  // A user can cancel a scheduled retry.
  {
    const x = setup();
    x.playBtn._emit('click'); await flush();
    x.audio._emit('error');
    x.playBtn._emit('click');
    await x.advance(60000);
    assert.equal(x.audio.playCount, 1);
  }
  // Repeated failure eventually stops rather than retrying indefinitely.
  {
    const x = setup();
    x.audio.play = async () => { x.audio.playCount++; throw Error('offline'); };
    x.playBtn._emit('click'); await flush();
    for (const wait of [2000,4000,8000,16000,30000]) await x.advance(wait);
    assert.equal(x.audio.playCount, 6);
    assert.match(x.status.textContent, /Tap play to try again/);
    await x.advance(60000);
    assert.equal(x.audio.playCount, 6);
  }
  console.log('Player recovery tests passed.');
})().catch(err => {console.error(err); process.exitCode = 1;});
