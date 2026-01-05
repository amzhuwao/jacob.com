#!/bin/bash
# PWA Icon Generator Script
# This script generates all required PWA icon sizes from the source SVG

SIZES=(72 96 128 144 152 192 384 512)
SOURCE_SVG="icon-source.svg"

echo "PWA Icon Generation Script"
echo "=========================="
echo ""
echo "To generate PNG icons from the SVG source, you have several options:"
echo ""
echo "Option 1: Install ImageMagick"
echo "  sudo apt-get update && sudo apt-get install imagemagick"
echo "  Then run:"
for size in "${SIZES[@]}"; do
  echo "  convert -background none -resize ${size}x${size} $SOURCE_SVG icon-${size}x${size}.png"
done
echo ""
echo "Option 2: Install rsvg-convert (recommended for better quality)"
echo "  sudo apt-get install librsvg2-bin"
echo "  Then run:"
for size in "${SIZES[@]}"; do
  echo "  rsvg-convert -w $size -h $size $SOURCE_SVG > icon-${size}x${size}.png"
done
echo ""
echo "Option 3: Use online converter"
echo "  1. Visit: https://cloudconvert.com/svg-to-png"
echo "  2. Upload: $SOURCE_SVG"
echo "  3. Set dimensions and download for each size: ${SIZES[*]}"
echo ""
echo "Option 4: Use favicon generator (easiest)"
echo "  1. Visit: https://realfavicongenerator.net/"
echo "  2. Upload a 512x512 PNG version of the logo"
echo "  3. Download the generated icon package"
echo "  4. Extract to this directory"
echo ""

# Check if any converter is available
if command -v convert &> /dev/null; then
    echo "ImageMagick detected! Generating icons..."
    for size in "${SIZES[@]}"; do
        convert -background none -resize ${size}x${size} $SOURCE_SVG icon-${size}x${size}.png
        echo "✓ Generated icon-${size}x${size}.png"
    done
    echo "All icons generated successfully!"
elif command -v rsvg-convert &> /dev/null; then
    echo "rsvg-convert detected! Generating icons..."
    for size in "${SIZES[@]}"; do
        rsvg-convert -w $size -h $size $SOURCE_SVG > icon-${size}x${size}.png
        echo "✓ Generated icon-${size}x${size}.png"
    done
    echo "All icons generated successfully!"
else
    echo "No SVG converter found. Please install one of the above tools or use online converter."
    exit 1
fi
