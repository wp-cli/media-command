#!/usr/bin/env bash

set -ex

# ImageMagick/Imagick versions to use
IMAGEMAGICK_VERSION='6.9.7-10'
IMAGICK_VERSION='3.4.3'

install_imagemagick() {
	curl -O "https://www.imagemagick.org/download/releases/ImageMagick-$IMAGEMAGICK_VERSION.tar.xz" -f
	tar xf "ImageMagick-$IMAGEMAGICK_VERSION.tar.xz"
	rm "ImageMagick-$IMAGEMAGICK_VERSION.tar.xz"
	cd "ImageMagick-$IMAGEMAGICK_VERSION"

	./configure --prefix="$HOME/opt/$TRAVIS_PHP_VERSION" --with-perl=no
	make
	make install

	# Don't need doc - saves around 24M.
	if [[ -d "$HOME/opt/$TRAVIS_PHP_VERSION/share/doc" ]]; then rm -rf "$HOME/opt/$TRAVIS_PHP_VERSION/share/doc"; fi

	cd "$TRAVIS_BUILD_DIR"
}

# Install ImageMagick if the current version isn't up to date
PATH="$HOME/opt/$TRAVIS_PHP_VERSION/bin:$PATH" identify -version | grep "$IMAGEMAGICK_VERSION" || install_imagemagick

# Install Imagick for PHP
echo "$HOME/opt/$TRAVIS_PHP_VERSION" | pecl install -f "imagick-$IMAGICK_VERSION"
