# composer-plugin-nixify

Generates a [Nix] expression to build a [Composer] project.

- A default configure-phase that runs `composer install` in your project. (May
  be all that's needed to build your project.)

- A default install-phase that creates executables for you based on `"bin"` in
  your `composer.json`, making your package readily installable.

- Granular fetching of dependencies in Nix, speeding up rebuilds and
  potentially allowing downloads to be shared between projects.

- Preloading of your Composer cache into the Nix store, speeding up local
  `nix-build`.

- Automatically keeps your Nix expression up-to-date as you `composer require`
  / `composer update` dependencies.

- **No Nix installation required** for the plugin itself, so it should be safe
  to add to your project even if some developers don't use Nix.

[nix]: https://nixos.org
[composer]: https://getcomposer.org

Related projects:

- [composer2nix]: Does a similar job, but as a separate command. By comparison,
  this plugin tries to automate the process and make things easy for Nix and
  non-Nix devs alike.

- [yarn-plugin-nixify]: Similar solution for Node.js with Yarn v2.

[composer2nix]: https://github.com/svanderburg/composer2nix
[yarn-plugin-nixify]: https://github.com/stephank/yarn-plugin-nixify

## Usage

The Nixify plugin should work fine with Composer versions all the way back to
1.3.0, and also supports Composer 2.0.

To use the plugin:

```sh
# Install the plugin
composer require stephank/composer-plugin-nixify

# Build your project with Nix
nix-build
```

Running Composer with this plugin enabled will generate two files:

- `composer-project.nix`: This file is always overwritten, and contains a basic
  derivation for your project.

- `default.nix`: Only generated if it does not exist yet. This file is intended
  to be customized with any project-specific logic you need.

This may already build successfully! But if your project needs extra build
steps, you may have to customize `default.nix` a bit. Some examples of what's
possible:

```nix
{ pkgs ? import <nixpkgs> { } }:

let

  # Example of providing a different source tree.
  src = pkgs.lib.cleanSource ./.;

  project = pkgs.callPackage ./composer-project.nix {

    # Example of selecting a specific version of PHP.
    php = pkgs.php74;

  } src;

in project.overrideAttrs (oldAttrs: {

  # Example of overriding the default package name taken from composer.json.
  name = "myproject";

  # Example of adding packages to the build environment.
  buildInputs = oldAttrs.buildInputs ++ [ pkgs.imagemagick ];

  # Example of invoking a build step in your project.
  buildPhase = ''
    composer run lint
  '';

})
```
