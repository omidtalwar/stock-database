<?php
// Generates PNG app icons for the PWA manifest — no auth required
$size = in_array((int)($_GET['size'] ?? 192), [192, 512]) ? (int)$_GET['size'] : 192;

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000');

$img = imagecreatetruecolor($size, $size);
imagesavealpha($img, true);

// Background: rounded-rect gradient approximation via solid fill
$bg = imagecolorallocate($img, 0, 103, 192); // #0067C0
imagefill($img, 0, 0, $bg);

// Rounded corners (paint corner pixels transparent)
$trans  = imagecolorallocatealpha($img, 0, 0, 0, 127);
$radius = (int)($size * 0.22);
// Four corners — flood-fill approach: paint arcs
imagecolortransparent($img, $trans);
// Top-left
imagefilledarc($img, $radius, $radius, $radius * 2, $radius * 2, 180, 270, $trans, IMG_ARC_PIE);
imagefilledrectangle($img, 0, 0, $radius, $radius, $trans);
// Top-right
imagefilledarc($img, $size - $radius, $radius, $radius * 2, $radius * 2, 270, 360, $trans, IMG_ARC_PIE);
imagefilledrectangle($img, $size - $radius, 0, $size, $radius, $trans);
// Bottom-left
imagefilledarc($img, $radius, $size - $radius, $radius * 2, $radius * 2, 90, 180, $trans, IMG_ARC_PIE);
imagefilledrectangle($img, 0, $size - $radius, $radius, $size, $trans);
// Bottom-right
imagefilledarc($img, $size - $radius, $size - $radius, $radius * 2, $radius * 2, 0, 90, $trans, IMG_ARC_PIE);
imagefilledrectangle($img, $size - $radius, $size - $radius, $size, $size, $trans);

// "FZL" text centred
$white    = imagecolorallocate($img, 255, 255, 255);
$fontSize = (int)($size * 0.28);
$font     = 5; // built-in GD font (largest available without TTF)

// Use imagestring with GD built-in font — scale by repeating characters per pixel
// For large sizes use imagestring scaled trick, for small use standard
$text   = 'FZL';
$charW  = imagefontwidth($font);
$charH  = imagefontheight($font);
$textW  = $charW * strlen($text);
$scale  = max(1, (int)($size / 80));
$startX = (int)(($size - $textW * $scale) / 2);
$startY = (int)(($size - $charH * $scale) / 2);

// Scale by drawing each character pixel-by-pixel using imagestring on a temp image
$tmp = imagecreatetruecolor($textW, $charH);
$tmpBg   = imagecolorallocate($tmp, 0, 103, 192);
$tmpWhite = imagecolorallocate($tmp, 255, 255, 255);
imagefill($tmp, 0, 0, $tmpBg);
imagestring($tmp, $font, 0, 0, $text, $tmpWhite);
imagecopyresized($img, $tmp, $startX, $startY, 0, 0, $textW * $scale, $charH * $scale, $textW, $charH);
imagedestroy($tmp);

imagepng($img);
imagedestroy($img);
