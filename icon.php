<?php
// PWA icon generator — no auth, public
$size = in_array((int)($_GET['size'] ?? 192), [192, 512]) ? (int)$_GET['size'] : 192;

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000');

$img   = imagecreatetruecolor($size, $size);
$blue  = imagecolorallocate($img, 0, 103, 192);
$white = imagecolorallocate($img, 255, 255, 255);

imagefill($img, 0, 0, $blue);

// Draw "FZL" text scaled up
$font  = 5;
$text  = 'FZL';
$cw    = imagefontwidth($font);
$ch    = imagefontheight($font);
$scale = max(2, (int)($size / 48));
$tw    = $cw * strlen($text) * $scale;
$th    = $ch * $scale;
$x     = (int)(($size - $tw) / 2);
$y     = (int)(($size - $th) / 2);

// Scale by rendering to small canvas then resizing
$tmp = imagecreatetruecolor($cw * strlen($text), $ch);
$tmpBg = imagecolorallocate($tmp, 0, 103, 192);
$tmpFg = imagecolorallocate($tmp, 255, 255, 255);
imagefill($tmp, 0, 0, $tmpBg);
imagestring($tmp, $font, 0, 0, $text, $tmpFg);
imagecopyresized($img, $tmp, $x, $y, 0, 0, $tw, $th, $cw * strlen($text), $ch);
imagedestroy($tmp);

imagepng($img);
imagedestroy($img);
