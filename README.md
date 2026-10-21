# PrestaShop Installer

Global CLI for creating modules from
[prestashop-module](https://github.com/dartmoon-io/prestashop-module).
The installer is a separate Composer package; generated modules contain their
own setup/build tools and have no dependency on this installer.

## Install globally

Once the package is published:

```sh
composer global require dartmoon/prestashop-installer
```

Add Composer's global bin directory to your PATH. Find it with:

```sh
composer global config bin-dir --absolute
```

For a POSIX shell, the current session can use:

```sh
export PATH="$(composer global config bin-dir --absolute):$PATH"
```

Persist the equivalent PATH setting in your shell profile. Windows users should
add the reported bin directory to their user PATH.

Requirements: PHP 8.2+, Composer 2.2+, and Git for Composer VCS downloads.
The default module dependency install also needs the Zip extension for build
tools. Node 20.19+ (20.x) or 22.12+ is optional and needed only when compiling
module assets with Vite; the boilerplate's `.nvmrc` selects Node 22.

## Create a module

From the directory that should contain the new project:

```sh
prestashop create-module mymodule
cd mymodule
composer build
```

The installer downloads `dartmoon/prestashop-module` from its GitHub repository
using Composer, configures the technical name and module metadata, then installs
runtime dependencies and the boilerplate's tools. Missing metadata prompts on
an interactive terminal; noninteractive execution uses defaults.

For explicit values:

```sh
prestashop create-module mymodule --namespace='Acme\MyModule' \
  --display-name='My module' --author='My company' \
  --description='A module for my shop.' --module-version=1.0.0 --no-interaction
```

Names start with a lowercase letter and contain 3–64 lowercase letters, digits
or underscores. Use non-reserved PHP identifiers and a unique application
namespace. Existing destination directories, files and symlinks are never
overwritten. A failed operation retains a newly created directory for inspection.

| Option | Purpose |
| --- | --- |
| `--directory=/path/to/mymodule` | Choose the destination; defaults to ./mymodule |
| `--template-version=dev-main` | Select a boilerplate branch/tag; dev-main is the default |
| `--template-path=/path/to/prestashop-module` | Copy a current unconfigured local checkout instead of downloading |
| `--toolkit-path=/path/to/prestashop-module-toolkit` | Configure a mirrored local Composer path repository for the toolkit |
| `--namespace`, `--display-name`, `--author`, `--description` | Supply module metadata |
| `--module-version=1.0.0` | Set the module's initial version; --version reports the installer version |
| `--no-install` | Configure files without installing module runtime/tools dependencies |
| `--no-interaction` | Use supplied values and defaults without prompts |

Relative template/toolkit/destination paths are resolved from the directory
where `prestashop` is invoked. No toolkit path is automatically inferred.
`--template-version` applies to downloads, not local checkout copies.
`--no-install` still downloads the template unless `--template-path` is supplied.

Downloaded boilerplate post-create scripts are skipped; the installer invokes
setup before the first dependency install, so metadata and the runtime lock are
created in the right order. Dependencies and VCS files from local template
checkouts are excluded. Composer child failures return a failing installer exit
code and stop subsequent installation steps.

## Use the local repositories before publication

Register this checkout in your global Composer configuration and install it:

```sh
composer global config repositories.prestashop-installer '{"type":"path","url":"/absolute/path/to/prestashop-installer","options":{"symlink":true}}'
composer global require 'dartmoon/prestashop-installer:@dev'
```

Then create a module from the current local source repositories:

```sh
prestashop create-module mymodule \
  --template-path=/absolute/path/to/prestashop-module \
  --toolkit-path=/absolute/path/to/prestashop-module-toolkit \
  --namespace='Acme\MyModule' --no-interaction
```

This uses the same globally installed CLI as the published workflow. An
unpublished installer/package or an older remote boilerplate is not sufficient
for the public download workflow; publish the updated skeleton, toolkit and
installer first. Remove the local Composer repository override when switching
to published releases.

## Development

From the installer checkout:

```sh
composer install
php bin/prestashop create-module --help
composer test
```

The tests need a sibling `prestashop-module` checkout, or `TEMPLATE_PATH` pointing
to one. They cover real local scaffolding, metadata quoting, existing destination
protection, symlink handling, template validation, error propagation and download/
setup/install ordering. The download test substitutes a fixture for the network
operation. CI checks PHP 8.2–8.5 after the repositories are published.

See the [boilerplate documentation](https://github.com/dartmoon-io/prestashop-module/tree/main/docs)
for module development, builds and shop installation, and the
[toolkit documentation](https://github.com/dartmoon-io/prestashop-module-toolkit/tree/main/docs)
for the shared libraries.

## Quality and local ecosystem verification

Run `composer quality` to check official PrestaShop PHP formatting,
`composer quality:fix` to apply it, and `composer verify` for formatting plus tests.
These tools are development dependencies and do not enter a generated module.
Tests use the sibling boilerplate or `TEMPLATE_PATH`. Download/process mocks
verify orchestration only; a real local template/toolkit run must also be tested.

After creation run `composer quality:fix` to format generated metadata. The
module keeps its runtime dependencies in the root and build dependencies in
`tools/`; install both before building. See the boilerplate's release policy for
`composer headers`, Vite and the complete `composer verify` workflow. Set
`TOOLKIT_PATH` for pipeline regression tests when working outside sibling checkouts.
