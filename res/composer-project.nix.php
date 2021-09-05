# This file is generated by composer-plugin-nixify.
# Manual changes might be lost - proceed with caution!

{ lib, php, phpPackages, unzip, stdenv, runCommandLocal, writeText, fetchurl

# Default fetcher.
, fetcher ? (args: fetchurl { inherit (args) name urls sha256; })

}: src:

with lib;

let

  composerPath = <?php echo $composerPath; ?>;
  cacheEntries = <?php echo $cacheEntries; ?>;
  localPackages = <?php echo $localPackages; ?>;

  # Shell snippet to collect all project dependencies.
  collectCacheScript = writeText "collect-cache.sh" (
    concatMapStrings (args: ''
      (
        cacheFile=${escapeShellArg args.filename}
        cacheFilePath="$COMPOSER_CACHE_DIR/files/$cacheFile"
        mkdir -p "$(dirname "$cacheFilePath")"
        cp ${escapeShellArg (fetcher args)} "$cacheFilePath"
      )
    '') cacheEntries
  );

  replaceLocalPaths = writeText "replace-local-paths.sh" (
    concatMapStrings (args: ''
      sed -i -e "s|\"${args.string}\"|\"${args.path}\"|" composer.lock
    '') localPackages
  );

in stdenv.mkDerivation {
  name = <?php echo $projectName; ?>;
  inherit src;

  # Make sure the build uses the right PHP version everywhere.
  # Also include unzip for Composer.
  buildInputs = [ php unzip ];

  # Defines the shell alias to run Composer.
  postHook = ''
    composer () {
      php "$NIX_COMPOSER_PATH" "$@"
    }
  '';

  configurePhase = ''
    runHook preConfigure

    # Set the cache directory for Composer.
    export COMPOSER_CACHE_DIR="$NIX_BUILD_TOP/.composer/cache"

    # Build the cache directory contents.
    source ${collectCacheScript}

    # Replace local package paths with their Nix store equivalent.
    source ${replaceLocalPaths}

    # Store the absolute path to Composer for the 'composer' alias.
    export NIX_COMPOSER_PATH="$(readlink -f ${escapeShellArg composerPath})"

    # Run normal Composer install to complete dependency installation.
    composer install

    runHook postConfigure
  '';

  buildPhase = ''
    runHook preBuild
    runHook postBuild
  '';

  installPhase = ''
    runHook preInstall

    mkdir -p $out/libexec $out/bin

    # Move the entire project to the output directory.
    mv $PWD "$out/libexec/$sourceRoot"
    cd "$out/libexec/$sourceRoot"

    # Update the path to Composer.
    export NIX_COMPOSER_PATH="$(readlink -f ${escapeShellArg composerPath})"

    # Invoke a plugin internal command to setup binaries.
    composer nixify-install-bin "$out/bin"

    runHook postInstall
  '';

  passthru = {
    inherit php;
  };
}
