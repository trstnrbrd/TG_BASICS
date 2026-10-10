/* Canvas signature pad — mouse, touch and stylus through Pointer Events.
 *
 *   const pad = new SignaturePad(canvasEl);
 *   pad.isEmpty();   pad.clear();   pad.toDataURL();  // trimmed PNG, transparent background
 *
 * The canvas is sized to its CSS box at the device pixel ratio, so strokes stay sharp on phones and tablets.
 */
class SignaturePad {
  constructor(canvas, opts = {}) {
    this.canvas = canvas;
    this.ctx = canvas.getContext("2d");
    this.ink = opts.ink || "#1A1814";
    this.width = opts.width || 2.4;
    this.strokes = 0;
    this.drawing = false;
    this.pts = [];
    this.resize();

    // touch-action: none keeps a finger drag from scrolling the page while signing
    canvas.style.touchAction = "none";
    canvas.addEventListener("pointerdown", (e) => this.start(e));
    canvas.addEventListener("pointermove", (e) => this.move(e));
    ["pointerup", "pointercancel", "pointerleave"].forEach((t) => canvas.addEventListener(t, () => this.end()));
  }

  resize() {
    const r = this.canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    this.canvas.width = Math.max(1, Math.round(r.width * dpr));
    this.canvas.height = Math.max(1, Math.round(r.height * dpr));
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    this.ctx.lineCap = "round";
    this.ctx.lineJoin = "round";
    this.ctx.strokeStyle = this.ink;
    this.ctx.lineWidth = this.width;
    this.strokes = 0;
  }

  point(e) {
    const r = this.canvas.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }

  start(e) {
    e.preventDefault();
    this.canvas.setPointerCapture(e.pointerId);
    this.drawing = true;
    const p = this.point(e);
    this.pts = [p];
    // a tap alone still leaves a dot
    this.ctx.beginPath();
    this.ctx.arc(p.x, p.y, this.width / 2, 0, Math.PI * 2);
    this.ctx.fillStyle = this.ink;
    this.ctx.fill();
    this.strokes++;
  }

  move(e) {
    if (!this.drawing) return;
    e.preventDefault();
    const p = this.point(e);
    const pts = this.pts;
    pts.push(p);
    if (pts.length === 2) {
      // the curves below start at the first midpoint; join it to the touch-down dot so the stroke has no gap
      const a = pts[0], b = pts[1];
      this.segment(a, { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
      return;
    }
    // quadratic curve through midpoints smooths out the jagged polyline of raw pointer samples
    const a = pts[pts.length - 3], b = pts[pts.length - 2], c = pts[pts.length - 1];
    const m1 = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    const m2 = { x: (b.x + c.x) / 2, y: (b.y + c.y) / 2 };
    this.ctx.beginPath();
    this.ctx.moveTo(m1.x, m1.y);
    this.ctx.quadraticCurveTo(b.x, b.y, m2.x, m2.y);
    this.ctx.stroke();
  }

  end() {
    // finish the half segment between the last midpoint and where the pen lifted
    const pts = this.pts;
    if (this.drawing && pts.length >= 2) {
      const a = pts[pts.length - 2], b = pts[pts.length - 1];
      this.segment({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }, b);
    }
    this.drawing = false;
    this.pts = [];
  }

  segment(from, to) {
    this.ctx.beginPath();
    this.ctx.moveTo(from.x, from.y);
    this.ctx.lineTo(to.x, to.y);
    this.ctx.stroke();
  }

  isEmpty() {
    return this.strokes === 0;
  }

  clear() {
    this.ctx.save();
    this.ctx.setTransform(1, 0, 0, 1, 0, 0);
    this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    this.ctx.restore();
    this.strokes = 0;
  }

  // PNG cropped to the drawn area plus a margin, so the stored image is small and prints at a sensible size.
  toDataURL() {
    const { width: w, height: h } = this.canvas;
    const data = this.ctx.getImageData(0, 0, w, h).data;
    let x0 = w, y0 = h, x1 = -1, y1 = -1;
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        if (data[(y * w + x) * 4 + 3] > 0) {
          if (x < x0) x0 = x;
          if (x > x1) x1 = x;
          if (y < y0) y0 = y;
          if (y > y1) y1 = y;
        }
      }
    }
    if (x1 < 0) return "";
    const pad = Math.round(12 * (window.devicePixelRatio || 1));
    x0 = Math.max(0, x0 - pad);
    y0 = Math.max(0, y0 - pad);
    x1 = Math.min(w - 1, x1 + pad);
    y1 = Math.min(h - 1, y1 + pad);
    const out = document.createElement("canvas");
    out.width = x1 - x0 + 1;
    out.height = y1 - y0 + 1;
    out.getContext("2d").drawImage(this.canvas, x0, y0, out.width, out.height, 0, 0, out.width, out.height);
    return out.toDataURL("image/png");
  }
}
