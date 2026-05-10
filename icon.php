<?php
// PWA / favicon PNG generator — public, no auth
$size = (int)($_GET['size'] ?? 192);
if (!in_array($size, [32, 192, 512])) $size = 192;

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000');

$img   = imagecreatetruecolor($size, $size);
imagesavealpha($img, true);

$blue1  = imagecolorallocate($img, 26,  135, 237);  // #1a87ed
$blue2  = imagecolorallocate($img, 0,   62,  146);  // #003e92
$white  = imagecolorallocate($img, 255, 255, 255);
$light  = imagecolorallocate($img, 200, 224, 248);  // roll highlight
$mid    = imagecolorallocate($img, 168, 196, 224);  // roll shadow

// Gradient background (top = blue1, bottom = blue2)
for ($y = 0; $y < $size; $y++) {
    $t   = $y / $size;
    $r   = (int)(26  + $t * (0   - 26));
    $g   = (int)(135 + $t * (62  - 135));
    $b   = (int)(237 + $t * (146 - 237));
    $col = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, 0, $y, $size - 1, $y, $col);
}

// Scale helpers
$s = $size / 100.0;
function sc($v) { global $s; return (int)round($v * $s); }

// Cloth bolt body (white rectangle)
$bx1 = sc(14); $by1 = sc(24); $bx2 = sc(86); $by2 = sc(52);
imagefilledrectangle($img, $bx1, $by1, $bx2, $by2, $white);

// Left end cap circles (simulate ellipse with circles)
imagefilledellipse($img, sc(14), sc(38), sc(18), sc(28), $light);
imagefilledellipse($img, sc(14), sc(38), sc(11), sc(17), $mid);
imagefilledellipse($img, sc(14), sc(38), sc(4),  sc(7),  $blue1);

// Right end cap
imagefilledellipse($img, sc(86), sc(38), sc(18), sc(28), $mid);
imagefilledellipse($img, sc(86), sc(38), sc(11), sc(17), imagecolorallocate($img, 140, 170, 210));
imagefilledellipse($img, sc(86), sc(38), sc(4),  sc(7),  $blue2);

// Fabric line stripes on body
$stripe = imagecolorallocatealpha($img, 0, 80, 160, 110);
foreach ([30, 34, 38, 42, 46] as $ly) {
    imageline($img, sc(14), sc($ly), sc(86), sc($ly), $stripe);
}

// "FZL" text
$font  = 5;
$text  = 'FZL';
$cw    = imagefontwidth($font);
$ch    = imagefontheight($font);
$scale = max(1, (int)($size / 32));
$tw    = $cw * strlen($text) * $scale;
$th    = $ch * $scale;
$tx    = (int)(($size - $tw) / 2);
$ty    = $size - sc(18) - (int)($th / 2);

$tmp   = imagecreatetruecolor($cw * strlen($text), $ch);
$tmpBg = imagecolorallocate($tmp, 0, 62, 146);
$tmpFg = imagecolorallocate($tmp, 255, 255, 255);
imagefill($tmp, 0, 0, $tmpBg);
imagestring($tmp, $font, 0, 0, $text, $tmpFg);
imagecopyresized($img, $tmp, $tx, $ty, 0, 0, $tw, $th, $cw * strlen($text), $ch);
imagedestroy($tmp);

imagepng($img);
imagedestroy($img);
