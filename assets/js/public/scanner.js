/* Scanner: the animated background of the client's QR page (modules/public/client.php), behind the Digital ID card.
 * A plain-JS port of the "Scanner" component from React Bits (MIT): the same WebGL2 shader (rippling lines that a
 * light sweep runs across, with glow, scanlines and grain), no React. Colours: TG-BASICS gold.
 *
 * It adds one fixed <canvas> behind the page (body gets .has-scanner). No WebGL2: nothing is added and the page
 * keeps its plain dark background. The pointer bends the lines; a tap or click outside the card sends a ping.
 * With "reduce motion" on it still sweeps, slower, without the intro (owner's choice for the landing page,
 * 2026-11-06). It pauses while the tab is hidden.
 */
(function () {
  'use strict';

  var OPTIONS = {
    color1: '#b8860b', color2: '#f5c842', color3: '#fff4d6',
    speed: 0.5, sweepSpeed: 0.22, sweepWidth: 2.6, trail: 0.8, scale: 1.5, frequency: 2, ripple: 0.22,
    bandDensity: 11, lineWidth: 1.2, glow: 0.6, direction: 0 /* vertical */, colorSpread: 0.6, brightness: 0.95,
    vignette: 0.2, scanline: 0.45, grain: 0.04, opacity: 0.9,
    mouseInteraction: true, mouseRadius: 0.5, mouseStrength: 0.7, attract: false, ping: true, intro: true
  };
  var MAX_PINGS = 6, INTRO_SECONDS = 2;

  var VERTEX = '#version 300 es\nvoid main() {\n  vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));\n  gl_Position = vec4(p * 2.0 - 1.0, 0.0, 1.0);\n}';

  var FRAGMENT = [
    '#version 300 es',
    'precision highp float;',
    'uniform vec2 uResolution;',
    'uniform float uWave;',
    'uniform float uSweep;',
    'uniform float uSweepWidth;',
    'uniform float uTrail;',
    'uniform float uScale;',
    'uniform float uFrequency;',
    'uniform float uRipple;',
    'uniform float uDensity;',
    'uniform float uLineWidth;',
    'uniform float uGlow;',
    'uniform float uDirection;',
    'uniform float uSpread;',
    'uniform float uBrightness;',
    'uniform float uVignette;',
    'uniform float uOpacity;',
    'uniform float uScanline;',
    'uniform float uRatio;',
    'uniform float uGrain;',
    'uniform float uTime;',
    'uniform vec2 uMouse;',
    'uniform float uPresence;',
    'uniform float uMouseRadius;',
    'uniform float uMouseStrength;',
    'uniform float uAttract;',
    'uniform vec3 uPings[' + MAX_PINGS + '];',
    'uniform float uIntro;',
    'uniform vec3 uColor1;',
    'uniform vec3 uColor2;',
    'uniform vec3 uColor3;',
    'out vec4 fragColor;',
    'float hash(vec2 p) { return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }',
    'float signal(vec2 p, float t) {',
    '  float w = sin(p.x * 1.3 + t * 0.7);',
    '  w += sin(p.y * 1.7 - t * 0.52) * 0.8;',
    '  w += sin((p.x + p.y) * 0.9 + t * 0.91) * 0.6;',
    '  w += sin((p.x - p.y) * 1.53 - t * 0.63) * 0.42;',
    '  return w * 0.35;',
    '}',
    'float smoother(float x) { x = clamp(x, 0.0, 1.0); return x * x * x * (x * (x * 6.0 - 15.0) + 10.0); }',
    'vec2 axes(vec2 p) {',
    '  if (uDirection < 0.5) return vec2(p.y, p.x);',
    '  if (uDirection < 1.5) return vec2(p.x, -p.y);',
    '  return vec2((p.x + p.y) * 0.70710678, (p.x - p.y) * 0.70710678);',
    '}',
    'float coverage(float lc, float width) {',
    '  float fw = max(fwidth(lc), 1e-5);',
    '  float dist = abs(fract(lc + 0.5) - 0.5) / fw;',
    '  float crowd = smoothstep(0.2, 0.55, fw);',
    '  float cover = clamp(width * 0.5 + 0.5 - dist, 0.0, 1.0) * min(width, 1.0);',
    '  return mix(cover, min(width * fw, 1.0), crowd);',
    '}',
    'void main() {',
    '  vec2 uv = (gl_FragCoord.xy * 2.0 - uResolution) / uResolution.y;',
    '  vec2 p = uv / max(uScale, 0.001);',
    '  vec2 ax = axes(p);',
    '  float sig = signal(p * uFrequency, uWave);',
    '  float coord = ax.x + sig * uRipple;',
    '  vec2 mp = uMouse / max(uScale, 0.001);',
    '  vec2 md = p - mp;',
    '  float radius = max(uMouseRadius / max(uScale, 0.001), 0.01);',
    '  float lens = exp(-dot(md, md) / (radius * radius)) * uPresence;',
    '  float mouseAxis = axes(mp).x + signal(mp * uFrequency, uWave) * uRipple;',
    '  coord -= (coord - mouseAxis) * lens * uMouseStrength * mix(0.55, -0.9, uAttract);',
    '  float pingLight = 0.0;',
    '  for (int i = 0; i < ' + MAX_PINGS + '; i++) {',
    '    vec3 ping = uPings[i];',
    '    if (ping.z < 0.0) continue;',
    '    float d = length(uv - ping.xy);',
    '    float age = ping.z;',
    '    float r = age * 1.1;',
    '    float w = 0.12 + age * 0.1;',
    '    float band = exp(-((d - r) * (d - r)) / (w * w));',
    '    float fade = exp(-age * 0.9) * smoothstep(0.0, 0.12, age);',
    '    coord += band * fade * 0.018 / max(uScale, 0.001);',
    '    pingLight += band * fade;',
    '  }',
    '  float u = fract(uSweep - coord / max(uSweepWidth, 0.05));',
    '  float tail = mix(11.0, 1.8, clamp(uTrail, 0.0, 1.0));',
    '  float lead = smoothstep(0.84, 1.0, u);',
    '  lead *= lead;',
    '  float sweep = max(exp(-u * tail), lead);',
    '  float head = max(exp(-u * 26.0), lead * lead * lead);',
    '  float introFront = mix(-1.6, 1.6, smoother(uIntro)) / max(uScale, 0.001);',
    '  float reveal = 1.0 - smoothstep(introFront - 0.05, introFront + 0.3, coord);',
    '  float introHead = exp(-abs(coord - introFront) * 14.0) * (1.0 - uIntro) * step(0.0001, 1.0 - uIntro);',
    '  reveal = mix(reveal, 1.0, step(0.9999, uIntro));',
    '  float lit = clamp(sweep * 0.86 + lens * uMouseStrength * 0.7 + pingLight * 0.9 + introHead, 0.0, 1.6);',
    '  float hot = clamp(head + introHead + pingLight * 0.35, 0.0, 1.0);',
    '  float lc = coord * uDensity;',
    '  float width = uLineWidth * (1.0 + lit * 1.4 + hot * 0.9);',
    '  float thread = 0.62 + 0.38 * hash(vec2(floor(lc + 0.5), 3.7));',
    '  float split = uSpread * (0.5 + lit * 1.3) * max(fwidth(lc), 1e-5);',
    '  float cg = coverage(lc, width);',
    '  float cr = coverage(lc + split, width);',
    '  float cb = coverage(lc - split, width);',
    '  float fw = max(fwidth(lc), 1e-5);',
    '  float dist = abs(fract(lc + 0.5) - 0.5) / fw;',
    '  float crowd = smoothstep(0.2, 0.55, fw);',
    '  float halo = exp(-dist / (3.0 + uGlow * 9.0)) * (1.0 - crowd) * uGlow * lit * 1.15;',
    '  float base = 0.32 * reveal;',   // lines stay visible where the sweep is not (was 0.11: the page looked half empty)
    '  float level = (base + lit * (1.0 - base)) * thread * uBrightness;',
    '  vec3 tone = mix(uColor1, uColor2, smoothstep(0.05, 0.75, lit));',
    '  tone = mix(tone, uColor3, hot);',
    '  vec3 haloTone = mix(uColor1, uColor2, 0.65);',
    '  float vx = smoother((1.0 - abs(uv.x / (uResolution.x / uResolution.y))) / 0.6);',
    '  float vy = smoother((1.0 - abs(uv.y)) / 0.6);',
    '  float vignette = mix(1.0, vx * vy, uVignette);',
    '  vec3 cover3 = vec3(cr, cg, cb);',
    '  float alphaLine = max(max(cr, cg), cb) * level;',
    '  vec3 colLine = tone * cover3 * level;',
    '  float grain = (hash(gl_FragCoord.xy + fract(uTime) * 91.7) - 0.5) * uGrain;',
    '  float scan = 1.0 - uScanline * 0.55 * (0.5 + 0.5 * cos(gl_FragCoord.y * 6.2831853 / (3.0 * uRatio)));',
    '  vec3 col = (colLine + haloTone * halo) * vignette * scan;',
    '  float alpha = clamp(max(alphaLine, halo) * vignette * scan + grain * max(alphaLine, 0.04), 0.0, 1.0);',
    '  col = clamp(col + grain * 0.6 * alpha, 0.0, 1.0);',
    '  col = min(col, vec3(alpha));',
    '  fragColor = vec4(col * uOpacity, alpha * uOpacity);',
    '}'
  ].join('\n');

  function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }
  function toRgb(hex) {
    var h = String(hex).replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var n = parseInt(h.slice(0, 6), 16);
    return isNaN(n) ? [1, 1, 1] : [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
  }
  function compile(gl, type, src) {
    var sh = gl.createShader(type);
    if (!sh) return null;
    gl.shaderSource(sh, src); gl.compileShader(sh);
    if (gl.getShaderParameter(sh, gl.COMPILE_STATUS)) return sh;
    gl.deleteShader(sh);
    return null;
  }
  function link(gl) {
    var v = compile(gl, gl.VERTEX_SHADER, VERTEX), f = compile(gl, gl.FRAGMENT_SHADER, FRAGMENT), prog = gl.createProgram();
    if (!v || !f || !prog) return null;
    gl.attachShader(prog, v); gl.attachShader(prog, f); gl.linkProgram(prog);
    gl.deleteShader(v); gl.deleteShader(f);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) { gl.deleteProgram(prog); return null; }
    var uniforms = {}, total = gl.getProgramParameter(prog, gl.ACTIVE_UNIFORMS);
    for (var i = 0; i < total; i++) {
      var info = gl.getActiveUniform(prog, i);
      if (info) uniforms[info.name.replace(/\[0\]$/, '')] = gl.getUniformLocation(prog, info.name);
    }
    return { program: prog, uniforms: uniforms };
  }

  /* Adaptive quality (2026-11-07): a full-screen shader 60 times a second is too much for a low-end phone or an
   * old laptop (measured: ~20 fps with no GPU and a slow CPU, typing on the page lagged). So it watches the real
   * frame time and steps down: first 30 fps and a lower internal resolution (the soft background hides it), then
   * lower again, and if even the lowest is slow it stops on a still frame. A device that says it is weak
   * (<= 2 GB RAM or <= 2 cores) starts lower; "Data Saver" on gets one still frame. canvas.dataset.quality shows
   * the level ("0" = full, "2@30", "static") for tests. */
  function makeQuality(canvas) {
    var dpr = Math.min(window.devicePixelRatio || 1, 2), tiers = [];
    [dpr, 1, 0.75, 0.5].forEach(function (r) {
      r = Math.min(r, dpr);
      if (!tiers.length || r < tiers[tiers.length - 1] - 0.01) tiers.push(r);
    });
    var conn = navigator.connection || {};
    var weak = (navigator.deviceMemory > 0 && navigator.deviceMemory <= 2) || (navigator.hardwareConcurrency > 0 && navigator.hardwareConcurrency <= 2);
    var q = { tier: weak ? Math.max(0, tiers.length - 2) : 0, cap: weak ? 30 : 0, frozen: !!conn.saveData };
    var born = performance.now(), sum = 0, count = 0;
    q.ratio = function () { return tiers[q.tier]; };
    q.mark = function () { canvas.dataset.quality = q.frozen ? 'static' : q.tier + (q.cap ? '@' + q.cap : ''); };
    // true = too soon for the next frame under the fps cap (the loop keeps waiting without drawing)
    q.skip = function (now, last) { return !!(q.cap && last && now - last < 1000 / q.cap - 3); };
    // one frame gap in ms; returns 'resize' (stepped down) / 'freeze' (stop on a still frame) / null
    q.sample = function (gap, now) {
      if (now - born < 1000 || gap > 250) return null;   // warm-up (shader compile, intro) and tab switches don't count
      sum += gap; count++;
      if (sum < 1500) return null;
      var avg = sum / count; sum = 0; count = 0;
      if (avg <= (q.cap ? 45 : 28)) return null;
      q.cap = 30;
      if (q.tier < tiers.length - 1) { q.tier++; q.mark(); return 'resize'; }
      q.frozen = true; q.mark(); return 'freeze';
    };
    q.mark();
    return q;
  }

  function start() {
    var canvas = document.createElement('canvas');
    canvas.className = 'scanner-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    // fixed behind everything (z-index -1 paints above the page background, under all the content), so neither
    // client.css nor the "link not found" page's own styles need to change
    // Sized to the screen itself (vw / vh), never to the page: a browser or extension that puts a transform or
    // filter on <body> turns "fixed" into "relative to body", and 100% then stopped where the content ended
    canvas.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:-1;pointer-events:none;display:block;';
    var gl = canvas.getContext('webgl2', { alpha: true, premultipliedAlpha: true, antialias: false, depth: false, stencil: false });
    if (!gl) return false;
    var shader = link(gl);
    if (!shader) return false;
    // a child of <html>, not <body>, for the same reason (body styles cannot make it follow the content)
    document.documentElement.insertBefore(canvas, document.body);
    document.body.classList.add('has-scanner');

    var s = OPTIONS, u = shader.uniforms, vao = gl.createVertexArray();
    var reduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
    var pace = reduce ? 0.5 : 1;
    var quality = makeQuality(canvas);
    var view = { width: 1, height: 1, ratio: 1 }, pointer = { x: 0, y: 0, inside: false }, mouse = { x: 0, y: 0, ready: false };
    var pings = new Float32Array(MAX_PINGS * 3);
    for (var k = 0; k < MAX_PINGS; k++) pings[k * 3 + 2] = -1;
    var c1 = toRgb(s.color1), c2 = toRgb(s.color2), c3 = toRgb(s.color3);
    var pingIndex = 0, presence = 0, wave = 0, sweep = -0.39, clock = 0, introClock = s.intro && !reduce && !quality.frozen ? 0 : 1;
    var raf = 0, last = 0;

    function toUv(x, y) { return [(x * 2 - view.width) / view.height, (view.height - y * 2) / view.height]; }

    function draw() {
      gl.viewport(0, 0, canvas.width, canvas.height);
      gl.clearColor(0, 0, 0, 0);
      gl.clear(gl.COLOR_BUFFER_BIT);
      gl.useProgram(shader.program);
      gl.uniform2f(u.uResolution, canvas.width, canvas.height);
      gl.uniform1f(u.uWave, wave);
      gl.uniform1f(u.uSweep, sweep);
      gl.uniform1f(u.uSweepWidth, s.sweepWidth);
      gl.uniform1f(u.uTrail, s.trail);
      gl.uniform1f(u.uScale, s.scale);
      gl.uniform1f(u.uFrequency, s.frequency);
      gl.uniform1f(u.uRipple, s.ripple);
      gl.uniform1f(u.uDensity, s.bandDensity);
      gl.uniform1f(u.uLineWidth, s.lineWidth * view.ratio);
      gl.uniform1f(u.uGlow, s.glow);
      gl.uniform1f(u.uDirection, s.direction);
      gl.uniform1f(u.uSpread, s.colorSpread);
      gl.uniform1f(u.uBrightness, s.brightness);
      gl.uniform1f(u.uVignette, s.vignette);
      gl.uniform1f(u.uOpacity, s.opacity);
      gl.uniform1f(u.uScanline, s.scanline);
      gl.uniform1f(u.uRatio, view.ratio);
      gl.uniform1f(u.uGrain, s.grain);
      gl.uniform1f(u.uTime, clock);
      gl.uniform2f(u.uMouse, mouse.x, mouse.y);
      gl.uniform1f(u.uPresence, s.mouseInteraction ? presence : 0);
      gl.uniform1f(u.uMouseRadius, s.mouseRadius);
      gl.uniform1f(u.uMouseStrength, s.mouseStrength);
      gl.uniform1f(u.uAttract, s.attract ? 1 : 0);
      gl.uniform3fv(u.uPings, pings);
      gl.uniform1f(u.uIntro, introClock);
      gl.uniform3fv(u.uColor1, c1);
      gl.uniform3fv(u.uColor2, c2);
      gl.uniform3fv(u.uColor3, c3);
      gl.enable(gl.BLEND);
      gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
      gl.bindVertexArray(vao);
      gl.drawArrays(gl.TRIANGLES, 0, 3);
    }

    function resize(noWake) {
      var box = canvas.getBoundingClientRect();
      view.width = Math.max(1, Math.round(box.width) || window.innerWidth);
      view.height = Math.max(1, Math.round(box.height) || window.innerHeight);
      view.ratio = quality.ratio();
      canvas.width = Math.max(1, Math.round(view.width * view.ratio));
      canvas.height = Math.max(1, Math.round(view.height * view.ratio));
      draw();
      if (noWake !== true) wake();
    }

    // Keep the canvas on the screen. A dark-mode extension or a browser setting can put a filter / transform on
    // <html>, which makes "position: fixed" scroll with the page, so after scrolling the bottom of the screen was
    // left bare. If the canvas has moved off the screen's corner, move it back (cheap: one rect read per frame).
    var shiftX = 0, shiftY = 0;
    function pin() {
      var r = canvas.getBoundingClientRect();
      if (Math.abs(r.top) > 0.5 || Math.abs(r.left) > 0.5) {
        shiftX -= r.left; shiftY -= r.top;
        canvas.style.top = shiftY + 'px';
        canvas.style.left = shiftX + 'px';
      }
    }
    window.addEventListener('scroll', pin, { passive: true });

    function frame(now) {
      raf = 0;
      if (quality.frozen || document.hidden) return;
      if (quality.skip(now, last)) { raf = requestAnimationFrame(frame); return; }
      pin();
      var change = last ? quality.sample(now - last, now) : null;
      if (change === 'freeze') { introClock = 1; draw(); return; }   // keep the last good frame, stop drawing
      var dt = last ? clamp((now - last) / 1000, 0, 1 / 20) : 1 / 60;
      last = now;
      clock += dt;
      wave += dt * s.speed * pace;
      sweep += dt * s.sweepSpeed * pace;
      if (introClock < 1) introClock = Math.min(1, introClock + dt / INTRO_SECONDS);
      var target = toUv(pointer.x, pointer.y), follow = 1 - Math.exp(-dt / 0.09);
      mouse.x += (target[0] - mouse.x) * follow;
      mouse.y += (target[1] - mouse.y) * follow;
      presence += ((pointer.inside && s.mouseInteraction ? 1 : 0) - presence) * (1 - Math.exp(-dt / 0.3));
      for (var i = 0; i < MAX_PINGS; i++) {
        if (pings[i * 3 + 2] < 0) continue;
        pings[i * 3 + 2] += dt;
        if (pings[i * 3 + 2] > 4) pings[i * 3 + 2] = -1;
      }
      draw();
      raf = requestAnimationFrame(frame);   // always sweeping; stops while the tab is hidden
      if (change === 'resize') resize(true);
    }
    function wake() { if (!raf && !quality.frozen && !document.hidden) { last = 0; raf = requestAnimationFrame(frame); } }

    window.addEventListener('pointermove', function (e) {
      var was = pointer.inside;
      pointer.inside = true; pointer.x = e.clientX; pointer.y = e.clientY;
      if (!was || !mouse.ready) { var uv = toUv(e.clientX, e.clientY); mouse.x = uv[0]; mouse.y = uv[1]; mouse.ready = true; }
    }, { passive: true });
    window.addEventListener('pointerdown', function (e) {
      // a tap on the card (its buttons, its photos) is not a ping
      if (!s.ping || (e.target.closest && e.target.closest('.pub-card, .pub-nav, a, button'))) return;
      var uv = toUv(e.clientX, e.clientY);
      pings[pingIndex * 3] = uv[0]; pings[pingIndex * 3 + 1] = uv[1]; pings[pingIndex * 3 + 2] = 0;
      pingIndex = (pingIndex + 1) % MAX_PINGS;
    }, { passive: true });
    document.documentElement.addEventListener('pointerleave', function () { pointer.inside = false; });
    document.addEventListener('visibilitychange', wake);
    window.addEventListener('resize', resize);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', resize);   // phone toolbars showing / hiding
    resize();
    return true;
  }

  function init() {
    if (document.body.classList.contains('has-scanner')) return;
    try { start(); } catch (e) { /* the plain dark background stays */ }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
