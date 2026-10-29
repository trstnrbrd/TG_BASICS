<?php
/**
 * Removes hidden metadata from an uploaded photo before it is kept: GPS location, phone make and model, date taken,
 * camera serial, editing software, comments. Repair photos can be shown to the client on the public QR page, so a
 * phone's GPS tag must never travel with them. Pure PHP (no GD, which is off on the local XAMPP).
 *
 *   strip_image_metadata($path, $mime)   // true = cleaned in place; false = could not read it, refuse the upload
 *
 * JPEG keeps one thing: its Orientation flag, rebuilt alone, so a portrait phone photo is not shown sideways.
 */

function strip_image_metadata(string $path, string $mime): bool
{
    $data = @file_get_contents($path);
    if ($data === false || $data === '') return false;
    $out = match ($mime) {
        'image/jpeg' => imgmeta_jpeg($data),
        'image/png'  => imgmeta_png($data),
        'image/webp' => imgmeta_webp($data),
        'image/gif'  => imgmeta_gif($data),
        default      => null,
    };
    if ($out === null) return false;
    return $out === $data || file_put_contents($path, $out) === strlen($out);
}

/** JPEG: drop APP1 (EXIF/XMP), APP13 (IPTC) and comment segments; keep a one-tag EXIF with the orientation. */
function imgmeta_jpeg(string $d): ?string
{
    if (strlen($d) < 4 || substr($d, 0, 2) !== "\xFF\xD8") return null;
    $out = "\xFF\xD8";
    $orientation = 1;
    $pos = 2;
    $len = strlen($d);
    while ($pos + 4 <= $len) {
        if ($d[$pos] !== "\xFF") return null;
        $marker = ord($d[$pos + 1]);
        if ($marker === 0xFF) { $pos++; continue; }                 // fill byte
        if ($marker === 0xDA) {                                       // start of scan: image data to the end
            if ($orientation !== 1) $out .= imgmeta_jpeg_orientation_segment($orientation);
            return $out . substr($d, $pos);
        }
        if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) { $out .= substr($d, $pos, 2); $pos += 2; continue; }
        $seglen = unpack('n', substr($d, $pos + 2, 2))[1];
        if ($seglen < 2 || $pos + 2 + $seglen > $len) return null;
        $seg = substr($d, $pos, 2 + $seglen);
        if ($marker === 0xE1) {                                       // APP1: EXIF or XMP
            if (substr($seg, 4, 6) === "Exif\0\0") $orientation = imgmeta_exif_orientation(substr($seg, 10)) ?? $orientation;
        } elseif ($marker !== 0xED && $marker !== 0xFE) {             // not IPTC, not a comment: keep
            $out .= $seg;
        }
        $pos += 2 + $seglen;
    }
    return null;   // no image data found
}

/** The Orientation tag (1–8) from an EXIF TIFF block, or null. */
function imgmeta_exif_orientation(string $tiff): ?int
{
    if (strlen($tiff) < 8) return null;
    $le = substr($tiff, 0, 2) === 'II';
    if (!$le && substr($tiff, 0, 2) !== 'MM') return null;
    $u16 = fn($o) => $o + 2 <= strlen($tiff) ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : null;
    $u32 = fn($o) => $o + 4 <= strlen($tiff) ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : null;
    $ifd = $u32(4);
    $n   = $ifd !== null ? $u16($ifd) : null;
    if ($n === null || $n > 500) return null;
    for ($i = 0; $i < $n; $i++) {
        $e = $ifd + 2 + 12 * $i;
        if ($u16($e) === 0x0112) {
            $v = $u16($e + 8);
            return ($v !== null && $v >= 1 && $v <= 8) ? $v : null;
        }
    }
    return null;
}

/** A minimal APP1 EXIF segment holding only the Orientation tag. */
function imgmeta_jpeg_orientation_segment(int $orientation): string
{
    $tiff = "II\x2A\x00" . pack('V', 8)                    // little-endian TIFF header, first IFD at 8
          . pack('v', 1)                                     // 1 entry
          . pack('vvV', 0x0112, 3, 1) . pack('v', $orientation) . "\0\0"   // Orientation, SHORT, count 1
          . pack('V', 0);                                    // no next IFD
    $body = "Exif\0\0" . $tiff;
    return "\xFF\xE1" . pack('n', strlen($body) + 2) . $body;
}

/** PNG: drop text, EXIF and timestamp chunks. */
function imgmeta_png(string $d): ?string
{
    if (substr($d, 0, 8) !== "\x89PNG\r\n\x1A\n") return null;
    $out = substr($d, 0, 8);
    $pos = 8;
    $len = strlen($d);
    while ($pos + 12 <= $len) {
        $clen = unpack('N', substr($d, $pos, 4))[1];
        $type = substr($d, $pos + 4, 4);
        if ($pos + 12 + $clen > $len) return null;
        if (!in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) $out .= substr($d, $pos, 12 + $clen);
        $pos += 12 + $clen;
        if ($type === 'IEND') return $out;
    }
    return null;
}

/** WebP: drop the EXIF and XMP chunks and clear their flags. */
function imgmeta_webp(string $d): ?string
{
    if (strlen($d) < 12 || substr($d, 0, 4) !== 'RIFF' || substr($d, 8, 4) !== 'WEBP') return null;
    $body = '';
    $pos  = 12;
    $len  = strlen($d);
    while ($pos + 8 <= $len) {
        $type = substr($d, $pos, 4);
        $clen = unpack('V', substr($d, $pos + 4, 4))[1];
        $full = 8 + $clen + ($clen & 1);                      // chunks are padded to an even size
        if ($pos + 8 + $clen > $len) return null;
        $chunk = substr($d, $pos, min($full, $len - $pos));
        if ($type === 'VP8X' && $clen >= 1) $chunk[8] = chr(ord($chunk[8]) & ~0x0C);   // EXIF + XMP flags off
        if ($type !== 'EXIF' && $type !== 'XMP ') $body .= $chunk;
        $pos += $full;
    }
    return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
}

/** GIF: drop comment blocks. GIFs carry no location, so a file that can't be walked is kept as it is. */
function imgmeta_gif(string $d): ?string
{
    if (!in_array(substr($d, 0, 6), ['GIF87a', 'GIF89a'], true)) return null;
    return strpos($d, "\x21\xFE") === false ? $d : (imgmeta_gif_walk($d) ?? $d);
}

function imgmeta_gif_walk(string $d): ?string
{
    $len = strlen($d);
    if ($len < 13) return null;
    $flags = ord($d[10]);
    $pos = 13 + (($flags & 0x80) ? 3 * (2 << ($flags & 7)) : 0);   // header + global colour table
    $out = substr($d, 0, $pos);
    $sub = function (int $p) use ($d, $len): ?int {                 // skip a run of data sub-blocks
        while ($p < $len) { $n = ord($d[$p]); $p++; if ($n === 0) return $p; $p += $n; }
        return null;
    };
    while ($pos < $len) {
        $b = $d[$pos];
        if ($b === "\x3B") return $out . "\x3B";                    // trailer
        if ($b === "\x21") {                                          // extension
            $end = $sub($pos + 2);
            if ($end === null) return null;
            if ($d[$pos + 1] !== "\xFE") $out .= substr($d, $pos, $end - $pos);
            $pos = $end;
        } elseif ($b === "\x2C") {                                    // image
            if ($pos + 10 > $len) return null;
            $lf = ord($d[$pos + 9]);
            $p = $pos + 10 + (($lf & 0x80) ? 3 * (2 << ($lf & 7)) : 0) + 1;   // + LZW min code size
            $end = $sub($p);
            if ($end === null) return null;
            $out .= substr($d, $pos, $end - $pos);
            $pos = $end;
        } else {
            return null;
        }
    }
    return null;
}
