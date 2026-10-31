// Shrinks an image in the browser before it is uploaded, so a 3-6 MB phone picture is stored at a few hundred KB.
//   tgCompressImage(file, "photo" | "document") -> Promise<File>   for an upload sent with fetch()
//   <input type="file" data-compress="photo" | "document">         for a normal form post, done on submit
// Saving as JPEG also leaves out GPS / phone / date data. PDF, GIF and anything the browser cannot read go up
// unchanged, and so does any failure here: the server's own checks still decide (size limit, strip_image_metadata()).
(function () {
  var KB = 1024;
  // document: readable text matters more than size, so it keeps more detail than a photo
  var PRESETS = {
    photo:    { maxSide: 1600, quality: 0.78, minQuality: 0.5, target: 400 * KB },
    document: { maxSide: 2200, quality: 0.88, minQuality: 0.7, target: 900 * KB },
  };

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error("unreadable")); };
      img.src = url;
    });
  }
  function toJpeg(canvas, quality) {
    return new Promise(function (resolve) { canvas.toBlob(resolve, "image/jpeg", quality); });
  }

  async function shrink(file, preset) {
    var p = PRESETS[preset];
    if (!p || !/^image\/(jpeg|png|webp)$/.test(file.type)) return file;
    try {
      var img = await loadImage(file);   // the browser applies the photo's rotation here
      var scale = Math.min(1, p.maxSide / Math.max(img.naturalWidth, img.naturalHeight));
      if (scale === 1 && file.size <= p.target) return file;

      var canvas = document.createElement("canvas");
      canvas.width = Math.round(img.naturalWidth * scale);
      canvas.height = Math.round(img.naturalHeight * scale);
      var ctx = canvas.getContext("2d");
      ctx.fillStyle = "#fff";   // a transparent PNG would turn black as a JPEG
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

      var quality = p.quality, blob = await toJpeg(canvas, quality);
      while (blob && blob.size > p.target && quality - 0.1 >= p.minQuality) {
        quality -= 0.1;
        blob = await toJpeg(canvas, quality);
      }
      canvas.width = canvas.height = 0;   // phones run out of memory fast with several large canvases
      if (!blob || blob.size >= file.size) return file;
      return new File([blob], file.name.replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg", lastModified: Date.now() });
    } catch (e) {
      return file;
    }
  }

  // One at a time: decoding several 12 MP pictures together can crash a phone's browser tab
  var queue = Promise.resolve();
  window.tgCompressImage = function (file, preset) {
    var result = queue.then(function () { return shrink(file, preset); });
    queue = result;
    return result;
  };

  // Normal form posts: hold the submit, swap the chosen files for the small ones, then submit again.
  // Capture phase, so the page's own submit handlers run only once, on the second submit.
  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (form._tgCompressDone) { form._tgCompressDone = false; return; }
    var inputs = Array.prototype.filter.call(form.querySelectorAll("input[type=file][data-compress]"), function (el) {
      return el.files && el.files.length;
    });
    if (!inputs.length || typeof DataTransfer === "undefined") return;

    e.preventDefault();
    e.stopPropagation();
    if (form._tgCompressing) return;   // a second click while the first is still working
    form._tgCompressing = true;
    document.documentElement.classList.add("tg-loading");

    Promise.all(inputs.map(function (el) {
      return Promise.all(Array.prototype.map.call(el.files, function (f) {
        return window.tgCompressImage(f, el.dataset.compress);
      })).then(function (small) {
        var dt = new DataTransfer();
        small.forEach(function (f) { dt.items.add(f); });
        el.files = dt.files;
      });
    })).then(function () {
      form._tgCompressing = false;
      form._tgCompressDone = true;
      if (form.requestSubmit) form.requestSubmit(e.submitter || undefined);
      else form.submit();
    });
  }, true);
})();
