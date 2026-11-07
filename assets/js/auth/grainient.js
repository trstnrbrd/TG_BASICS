/* Grainient: the animated, grainy gradient behind every sign-in page (login, logout, forgot / reset password,
 * activate, 2FA, TOTP, verify email). A plain-JS port of the "Grainient" component from React Bits (MIT): same
 * WebGL2 shaders (a noise-warped colour ramp mixed in OKLab, a soft "silk" shading and film grain), no React.
 *
 * It finds .auth-split, puts one <canvas> at the back of it and adds .has-grainient; auth-background.css then
 * hides the old CSS aurora. No WebGL2 (old phone, blocked GPU) or a shader that fails to compile: nothing is
 * added and the CSS aurora stays, so the page always has a background.
 *
 * Colours: the TG-BASICS black to gold (assets/css/auth/auth-background.css --bg-dark / --gold), brightest at
 * the top-left like the old aurora, mostly dark so the form stays easy to read. The pointer stirs it and a
 * click makes a small swirl. With "reduce motion" on (common on shop PCs) it still flows, slower, without the
 * intro (owner's choice for the landing page, 2026-11-06).
 */
(function () {
  'use strict';

  var COLORS = ['#0a0908', '#0a0908', '#17120a', '#4a3608', '#a8790b', '#e6b830'];
  var OPTIONS = {
    angle: -40,          // bright end towards the top-left
    bands: 1, speed: 0.8, flow: 0.85, scale: 1.1, softness: 1, silk: 0.3,
    grainAmount: 0.07, grainSize: 1.2, grainAnimated: true, contrast: 1.05, saturation: 1.05,
    variation: 0, interactive: true, stir: 1, clickSwirl: true, intro: true
  };

  var MAX_COLORS = 8, MAX_STIRS = 24, MAX_TWIRLS = 4, STIR_LIFE = 1.3, STIR_STEP = 0.06, TWIRL_LIFE = 2.6, INTRO_SECONDS = 1.6;

  var VERTEX = '#version 300 es\nout vec2 vUv;\nvoid main() {\n  vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));\n  vUv = p;\n  gl_Position = vec4(p * 2.0 - 1.0, 0.0, 1.0);\n}';

  var FIELD = [
    '#version 300 es',
    'precision highp float;',
    'precision highp int;',
    'uniform float uAspect;',
    'uniform float uTime;',
    'uniform vec3 uRamp[8];',
    'uniform int uCount;',
    'uniform int uType;',
    'uniform vec2 uDir;',
    'uniform float uExtent;',
    'uniform float uBands;',
    'uniform float uSoftness;',
    'uniform float uFlow;',
    'uniform float uScale;',
    'uniform float uSilk;',
    'uniform vec4 uStir[24];',
    'uniform float uStirWeight[24];',
    'uniform vec4 uTwirl[4];',
    'uniform vec2 uSeed;',
    'uniform float uContrast;',
    'uniform float uSaturation;',
    'uniform float uSettle;',
    'uniform float uReveal;',
    'in vec2 vUv;',
    'out vec4 outColor;',
    'vec2 hash2(vec2 p) {',
    '  vec3 q = fract(vec3(p.xyx) * vec3(0.1031, 0.103, 0.0973));',
    '  q += dot(q, q.yzx + 33.33);',
    '  return fract((q.xx + q.yz) * q.zy) * 2.0 - 1.0;',
    '}',
    'float noise(vec2 p) {',
    '  vec2 i = floor(p);',
    '  vec2 f = fract(p);',
    '  vec2 u = f * f * f * (f * (f * 6.0 - 15.0) + 10.0);',
    '  float a = dot(hash2(i), f);',
    '  float b = dot(hash2(i + vec2(1.0, 0.0)), f - vec2(1.0, 0.0));',
    '  float c = dot(hash2(i + vec2(0.0, 1.0)), f - vec2(0.0, 1.0));',
    '  float d = dot(hash2(i + vec2(1.0, 1.0)), f - vec2(1.0, 1.0));',
    '  return 0.5 + 0.5 * mix(mix(a, b, u.x), mix(c, d, u.x), u.y);',
    '}',
    'vec2 drift(vec2 p, float t) {',
    '  vec2 w1 = vec2(noise(p * 0.85 + vec2(t * 0.11, t * 0.07)), noise(p * 0.85 + vec2(4.1, 1.7) - vec2(t * 0.08, t * 0.12)));',
    '  vec2 w2 = vec2(noise(p * 1.55 + w1 * 1.8 + vec2(1.3, 7.1) + t * 0.05), noise(p * 1.55 + w1 * 1.8 + vec2(6.4, 2.2) - t * 0.06));',
    '  return (w1 - 0.5) * 1.1 + (w2 - 0.5) * 0.55;',
    '}',
    'vec2 warp(vec2 p) {',
    '  vec2 q = p / uScale;',
    '  if (uType == 1) {',
    '    float turn = uTime * 0.35;',
    '    q = mat2(cos(turn), sin(turn), -sin(turn), cos(turn)) * q;',
    '  } else {',
    '    q += vec2(-uDir.y, uDir.x) * uTime * 0.45;',
    '  }',
    '  return p + drift(q + uSeed, uTime) * uFlow * uScale * uSettle;',
    '}',
    'float coord(vec2 p) {',
    '  vec2 w = warp(p);',
    '  return uType == 1 ? 1.0 - length(w) * 1.1 : dot(w, uDir) / uExtent + 0.5;',
    '}',
    'vec3 labToRgb(vec3 lab) {',
    '  float l = lab.x + 0.3963377774 * lab.y + 0.2158037573 * lab.z;',
    '  float m = lab.x - 0.1055613458 * lab.y - 0.0638541728 * lab.z;',
    '  float s = lab.x - 0.0894841775 * lab.y - 1.2914855480 * lab.z;',
    '  l = l * l * l; m = m * m * m; s = s * s * s;',
    '  return vec3(4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,',
    '              -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,',
    '              -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s);',
    '}',
    'vec3 encode(vec3 c) {',
    '  c = clamp(c, 0.0, 1.0);',
    '  return mix(c * 12.92, 1.055 * pow(c, vec3(1.0 / 2.4)) - 0.055, step(0.0031308, c));',
    '}',
    'void main() {',
    '  vec2 p = (vUv - 0.5) * vec2(uAspect, 1.0);',
    '  for (int i = 0; i < 24; i++) {',
    '    float w = uStirWeight[i];',
    '    if (w <= 0.0) continue;',
    '    vec2 d = p - uStir[i].xy;',
    '    p -= uStir[i].zw * w * exp(-dot(d, d) / 0.045);',
    '  }',
    '  for (int i = 0; i < 4; i++) {',
    '    float angle = uTwirl[i].z;',
    '    if (angle == 0.0) continue;',
    '    vec2 d = p - uTwirl[i].xy;',
    '    float a = angle * exp(-dot(d, d) / (uTwirl[i].w * uTwirl[i].w));',
    '    float c = cos(a);',
    '    float s = sin(a);',
    '    p = uTwirl[i].xy + mat2(c, -s, s, c) * d;',
    '  }',
    '  float raw = coord(p);',
    '  float s = raw;',
    '  if (uBands > 1.0) {',
    '    s *= uBands;',
    '    float cycle = floor(s);',
    '    s = fract(s);',
    '    if (mod(cycle, 2.0) > 0.5) s = 1.0 - s;',
    '  }',
    '  s = clamp(s, 0.0, 1.0);',
    '  float scaled = s * float(uCount - 1);',
    '  int index = int(min(floor(scaled), float(uCount - 2)));',
    '  float f = scaled - float(index);',
    '  float edge = max(uSoftness * 0.5, fwidth(scaled) * 0.75);',
    '  float shaped = smoothstep(0.5 - edge, 0.5 + edge, f);',
    '  vec3 from = uRamp[0];',
    '  vec3 to = uRamp[1];',
    '  for (int i = 0; i < 7; i++) {',
    '    if (i == index) { from = uRamp[i]; to = uRamp[i + 1]; }',
    '  }',
    '  vec3 lab = mix(from, to, shaped);',
    '  if (uSilk > 0.0) {',
    '    vec2 grad = vec2(coord(p + vec2(0.004, 0.0)) - raw, coord(p + vec2(0.0, 0.004)) - raw) / 0.004;',
    '    vec2 slope = grad * sin(3.14159265 * clamp(raw, 0.0, 1.0) * uBands) * uSilk * 4.0;',
    '    vec3 normal = normalize(vec3(-slope, 1.0));',
    '    vec3 light = normalize(vec3(-uDir.y, uDir.x, 1.2));',
    '    float diffuse = dot(normal, light) - light.z;',
    '    lab = uRamp[0] + (lab - uRamp[0]) * (1.0 + (diffuse * 0.35 + max(diffuse, 0.0) * diffuse * 0.6) * uSilk);',
    '  }',
    '  lab = mix(uRamp[0], lab, smoothstep(0.0, 1.0, uReveal * 1.5 - length(vUv - 0.5) * 0.6));',
    '  lab.x = (lab.x - 0.55) * uContrast + 0.55;',
    '  lab.yz *= uSaturation;',
    '  outColor = vec4(encode(labToRgb(lab)), 1.0);',
    '}'
  ].join('\n');

  var COMPOSITE = [
    '#version 300 es',
    'precision highp float;',
    'uniform sampler2D uField;',
    'uniform float uGrain;',
    'uniform float uGrainSize;',
    'uniform float uFrame;',
    'in vec2 vUv;',
    'out vec4 outColor;',
    'float hash(vec2 p) {',
    '  vec3 q = fract(vec3(p.xyx) * 0.1031);',
    '  q += dot(q, q.yzx + 33.33);',
    '  return fract((q.x + q.y) * q.z);',
    '}',
    'float clump(vec2 p) {',
    '  vec2 i = floor(p);',
    '  vec2 f = fract(p);',
    '  vec2 u = f * f * (3.0 - 2.0 * f);',
    '  return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x), mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);',
    '}',
    'void main() {',
    '  vec3 color = texture(uField, vUv).rgb;',
    '  vec2 pixel = gl_FragCoord.xy + uFrame * vec2(17.0, 59.0);',
    '  float fine = hash(pixel) + hash(pixel + 7.13) - 1.0;',
    '  float coarse = (clump(pixel / max(uGrainSize, 1.0)) - 0.5) * 2.0;',
    '  float grain = mix(fine, coarse, clamp(uGrainSize - 1.0, 0.0, 1.0) * 0.8);',
    '  float luma = dot(color, vec3(0.2126, 0.7152, 0.0722));',
    '  color += grain * uGrain * (0.45 + 2.2 * luma * (1.0 - luma));',
    '  outColor = vec4(clamp(color, 0.0, 1.0), 1.0);',
    '}'
  ].join('\n');

  function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }
  function toLinear(v) { return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
  function hexToRgb(hex) {
    var h = hex.replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var n = parseInt(h, 16);
    return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
  }
  function toLab(rgb) {
    var lr = toLinear(rgb[0]), lg = toLinear(rgb[1]), lb = toLinear(rgb[2]);
    var l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb);
    var m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb);
    var s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb);
    return [0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
            1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
            0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s];
  }
  function compile(gl, type, src) {
    var sh = gl.createShader(type);
    if (!sh) return null;
    gl.shaderSource(sh, src); gl.compileShader(sh);
    if (gl.getShaderParameter(sh, gl.COMPILE_STATUS)) return sh;
    gl.deleteShader(sh);
    return null;
  }
  function link(gl, fragSrc) {
    var v = compile(gl, gl.VERTEX_SHADER, VERTEX), f = compile(gl, gl.FRAGMENT_SHADER, fragSrc), prog = gl.createProgram();
    if (!v || !f || !prog) return null;
    gl.attachShader(prog, v); gl.attachShader(prog, f); gl.linkProgram(prog);
    gl.deleteShader(v); gl.deleteShader(f);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) { gl.deleteProgram(prog); return null; }
    var uniforms = {}, count = gl.getProgramParameter(prog, gl.ACTIVE_UNIFORMS);
    for (var i = 0; i < count; i++) {
      var info = gl.getActiveUniform(prog, i);
      if (!info) continue;
      var name = info.name.replace(/\[0\]$/, '');
      uniforms[name] = gl.getUniformLocation(prog, name);
    }
    return { program: prog, uniforms: uniforms };
  }

  function start(container) {
    var canvas = document.createElement('canvas');
    canvas.className = 'grainient-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    var gl = canvas.getContext('webgl2', { alpha: false, antialias: false, depth: false, stencil: false });
    if (!gl) return false;
    var field = link(gl, FIELD), composite = link(gl, COMPOSITE);
    if (!field || !composite) return false;
    container.insertBefore(canvas, container.firstChild);
    container.classList.add('has-grainient');

    var s = OPTIONS, fu = field.uniforms, cu = composite.uniforms;
    var reduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
    var speed = s.speed * (reduce ? 0.5 : 1);
    var vao = gl.createVertexArray(), target = gl.createTexture(), buffer = gl.createFramebuffer();
    gl.bindTexture(gl.TEXTURE_2D, target);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    var fieldSize = { width: 0, height: 0 }, view = { width: 1, height: 1, ratio: 1 };

    var colors = COLORS.slice(0, MAX_COLORS);
    var ramp = new Float32Array(MAX_COLORS * 3);
    colors.forEach(function (c, i) { ramp.set(toLab(hexToRgb(c)), i * 3); });
    var stirs = new Float32Array(MAX_STIRS * 4), stirWeights = new Float32Array(MAX_STIRS), twirls = new Float32Array(MAX_TWIRLS * 4);
    var trail = [], spins = [];
    var pointer = { x: 0.5, y: 0.5, sx: 0.5, sy: 0.5, vx: 0, inside: false, fresh: true };
    var time = 0, introClock = s.intro && !reduce ? 0 : 1, frameSeed = 0, grainClock = 0, raf = 0, last = 0, visible = true;

    function draw() {
      var aspect = view.width / view.height, rad = s.angle * Math.PI / 180;
      gl.bindVertexArray(vao);
      gl.disable(gl.BLEND);
      gl.bindFramebuffer(gl.FRAMEBUFFER, buffer);
      gl.viewport(0, 0, fieldSize.width, fieldSize.height);
      gl.useProgram(field.program);
      gl.uniform1f(fu.uAspect, aspect);
      gl.uniform1f(fu.uTime, time);
      gl.uniform3fv(fu.uRamp, ramp);
      gl.uniform1i(fu.uCount, colors.length);
      gl.uniform1i(fu.uType, 0);
      gl.uniform2f(fu.uDir, Math.sin(rad), Math.cos(rad));
      gl.uniform1f(fu.uExtent, aspect * Math.abs(Math.sin(rad)) + Math.abs(Math.cos(rad)));
      gl.uniform1f(fu.uBands, s.bands);
      gl.uniform1f(fu.uSoftness, s.softness);
      gl.uniform1f(fu.uFlow, s.flow);
      gl.uniform1f(fu.uScale, s.scale);
      gl.uniform1f(fu.uSilk, s.silk);
      gl.uniform4fv(fu.uStir, stirs);
      gl.uniform1fv(fu.uStirWeight, stirWeights);
      gl.uniform4fv(fu.uTwirl, twirls);
      gl.uniform2f(fu.uSeed, s.variation * 3.17, s.variation * 1.91);
      gl.uniform1f(fu.uContrast, s.contrast);
      gl.uniform1f(fu.uSaturation, s.saturation);
      gl.uniform1f(fu.uSettle, introClock * introClock * (3 - 2 * introClock));
      gl.uniform1f(fu.uReveal, introClock);
      gl.drawArrays(gl.TRIANGLES, 0, 3);
      gl.bindFramebuffer(gl.FRAMEBUFFER, null);
      gl.viewport(0, 0, canvas.width, canvas.height);
      gl.useProgram(composite.program);
      gl.activeTexture(gl.TEXTURE0);
      gl.bindTexture(gl.TEXTURE_2D, target);
      gl.uniform1i(cu.uField, 0);
      gl.uniform1f(cu.uGrain, s.grainAmount);
      gl.uniform1f(cu.uGrainSize, s.grainSize * view.ratio);
      gl.uniform1f(cu.uFrame, frameSeed);
      gl.drawArrays(gl.TRIANGLES, 0, 3);
    }

    function resize() {
      view.width = Math.max(1, container.clientWidth);
      view.height = Math.max(1, container.clientHeight);
      view.ratio = Math.min(window.devicePixelRatio || 1, 2);
      canvas.width = Math.max(1, Math.round(view.width * view.ratio));
      canvas.height = Math.max(1, Math.round(view.height * view.ratio));
      var w = Math.max(1, Math.round(canvas.width / 2)), h = Math.max(1, Math.round(canvas.height / 2));
      if (w !== fieldSize.width || h !== fieldSize.height) {
        fieldSize.width = w; fieldSize.height = h;
        gl.bindTexture(gl.TEXTURE_2D, target);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA8, w, h, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
        gl.bindFramebuffer(gl.FRAMEBUFFER, buffer);
        gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, target, 0);
        gl.bindFramebuffer(gl.FRAMEBUFFER, null);
      }
      draw();
      wake();
    }

    function updateTouches(dt, aspect) {
      var follow = 1 - Math.exp(-dt / 0.06), px = pointer.sx, py = pointer.sy;
      pointer.sx += (pointer.x - pointer.sx) * follow;
      pointer.sy += (pointer.y - pointer.sy) * follow;
      var vx = ((pointer.sx - px) / dt) * aspect, vy = (pointer.sy - py) / dt;
      pointer.vx = vx;
      var head = trail[0];
      trail.forEach(function (t) { if (t.done) t.age += dt; });
      if (s.interactive && pointer.inside && Math.hypot(vx, vy) > 0.02) {
        if (!head || head.done) {
          trail.unshift({ x: 0, y: 0, vx: 0, vy: 0, age: 0, grow: 0, done: false });
          if (trail.length > MAX_STIRS) trail.pop();
        }
        var live = trail[0];
        live.x = (pointer.sx - 0.5) * aspect; live.y = pointer.sy - 0.5; live.vx = vx; live.vy = vy;
        live.grow = Math.min(1, live.grow + dt / STIR_STEP);
        live.done = live.grow >= 1;
      } else if (head) {
        head.done = true;
      }
      while (trail.length && trail[trail.length - 1].age > STIR_LIFE) trail.pop();
      for (var i = 0; i < MAX_STIRS; i++) {
        var t = trail[i];
        if (!t) { stirWeights[i] = 0; continue; }
        var fade = 1 - t.age / STIR_LIFE, cap = Math.min(1, 2.2 / Math.max(Math.hypot(t.vx, t.vy), 0.001));
        stirs.set([t.x, t.y, t.vx * cap, t.vy * cap], i * 4);
        stirWeights[i] = fade * fade * t.grow * s.stir * 0.05;
      }
      spins.forEach(function (sp) { sp.age += dt; });
      while (spins.length && spins[spins.length - 1].age > TWIRL_LIFE) spins.pop();
      for (var j = 0; j < MAX_TWIRLS; j++) {
        var sp = spins[j];
        if (!sp) { twirls[j * 4 + 2] = 0; continue; }
        var k = sp.age / TWIRL_LIFE, env = Math.min(1, sp.age / 0.18) * (1 - k) * (1 - k);
        twirls.set([sp.x, sp.y, sp.direction * 2.4 * env, 0.16 + 0.1 * Math.min(1, sp.age / 0.5)], j * 4);
      }
    }

    function frame(now) {
      raf = 0;
      if (!visible || document.hidden) return;
      var dt = last ? clamp((now - last) / 1000, 0.001, 0.05) : 1 / 60;
      last = now;
      time += dt * speed * 0.16;
      if (introClock < 1) introClock = Math.min(1, introClock + dt / INTRO_SECONDS);
      grainClock += dt;
      if (s.grainAnimated && !reduce && grainClock > 1 / 24) { grainClock = 0; frameSeed = (frameSeed + 1) % 97; }
      updateTouches(dt, view.width / view.height);
      draw();
      raf = requestAnimationFrame(frame);   // always flowing (speed > 0); stops while hidden or off screen
    }
    function wake() { if (!raf && visible && !document.hidden) raf = requestAnimationFrame(frame); }

    function locate(e) {
      var r = container.getBoundingClientRect();
      var x = (e.clientX - r.left) / Math.max(1, r.width), y = 1 - (e.clientY - r.top) / Math.max(1, r.height);
      pointer.inside = x >= 0 && x <= 1 && y >= 0 && y <= 1;
      pointer.x = x; pointer.y = y;
      if (pointer.fresh || !pointer.inside) { pointer.sx = x; pointer.sy = y; pointer.fresh = false; }
    }
    window.addEventListener('pointermove', function (e) { locate(e); if (pointer.inside || trail.length) wake(); }, { passive: true });
    window.addEventListener('pointerdown', function (e) {
      locate(e);
      // a click on the form (typing, buttons) is not a swirl
      if (!pointer.inside || !s.clickSwirl || (e.target.closest && e.target.closest('.auth-right form, .auth-right button, .auth-right a, .auth-right input, .swal2-container'))) return;
      spins.unshift({ x: (pointer.x - 0.5) * (view.width / view.height), y: pointer.y - 0.5, age: 0, direction: pointer.vx < 0 ? 1 : -1 });
      if (spins.length > MAX_TWIRLS) spins.pop();
      wake();
    }, { passive: true });
    document.documentElement.addEventListener('pointerleave', function () { pointer.inside = false; });
    document.addEventListener('visibilitychange', function () { last = 0; wake(); });
    if ('ResizeObserver' in window) new ResizeObserver(resize).observe(container); else window.addEventListener('resize', resize);
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        visible = entries.some(function (en) { return en.isIntersecting; });
        if (visible) { last = 0; wake(); }
      }).observe(container);
    }
    resize();
    return true;
  }

  function init() {
    var box = document.querySelector('.auth-split');
    if (!box || box.classList.contains('has-grainient')) return;
    try { start(box); } catch (e) { /* the CSS aurora stays */ }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
