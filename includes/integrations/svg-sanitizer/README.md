# Bundled SVG sanitizer

Bricks uses [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitizer),
licensed **GPL-2.0-or-later**, compatible with Bricks' GPLv2 distribution.
The upstream license is retained in `includes/integrations/svg-sanitizer/library/vendor/enshrined/svg-sanitize/LICENSE`.

The shipped package is generated with a `Bricks\Vendor` namespace prefix. Do not
edit vendor files or run Composer directly into the shipped vendor directory:
unscoped plugin copies must never decide which sanitizer Bricks executes.

## Rebuilding

Use PHP 8.2+ with Composer 2.10.3 on PATH (the generated runtime supports PHP 7.4+).
The build fixes root-package metadata so checkout branches and commit SHAs do not
affect the generated bundle:

```sh
# Only when updating the dependency: change the constraint if necessary, then:
composer update --working-dir=includes/integrations/svg-sanitizer/library --no-install --no-dev

# Rebuild from the committed lock files:
php scripts/build-svg-sanitizer.php

# Audit the runtime dependency, including its nested lock file:
composer audit --working-dir=includes/integrations/svg-sanitizer/library --locked --no-dev

# Verify the security and upload contracts:
php tests/unit/svg-sanitizer.test.php
php tests/unit/template-svg-import.test.php
php tests/unit/abilities/svg-sanitizer.test.php
php tests/unit/form-file-upload.test.php
```

On a disposable WordPress site with Bricks active, also run:

```sh
wp eval-file wp-content/themes/bricks/tests/wordpress/svg-sanitizer.php
```

The build installs the unmodified dependency into `.tmp/svg-sanitizer/source`,
prefixes it with the pinned MIT-licensed PHP-Scoper development tool, regenerates
Composer's authoritative autoloader, and replaces only the shipped vendor tree.
PHP-Scoper and its dependencies remain in ignored development directories.
Review and include the runtime lock file and generated vendor changes together.

## Security and compatibility contracts

`Bricks\Svg::sanitize_markup()` is the shared markup sanitizer. The byte adapter
`sanitize_contents()` preserves SVGZ compression and bounds decompressed input by
WordPress' upload limit. Uploads also require a successful, complete file write.
Icon and transfer imports and base64 media uploads sanitize before persistence,
then verify the written file against the sanitized bytes before registration.
Remote template-image downloads use the same sanitizer and write verification;
rejected SVGs use the existing placeholder instead of retaining their remote URL.
Rejected packaged icon assets cannot fall back to the original URL/attachment.
The existing ability names, schemas, error codes and permission gates are retained.

Bricks' existing allowed-tag/attribute filters remain supported. The upload bypass
filter remains an explicit trusted-code opt-out on upload/sideload/form hooks;
it does not disable mandatory sanitization in the icon or transfer import paths.
Remote-reference removal retains the existing upstream default (off).

Regression coverage includes GHSA-9rjx-3jch-6vjf, GHSA-vjg7-f2wj-gqqh,
GHSA-v383-3rw5-q8rf, GHSA-22wq-q86m-83fh, GHSA-m9xh-6747-9r6f,
GHSA-hrwm-vcc9-hcm4, GHSA-q546-85r6-f39q and GHSA-f7cw-vfxq-7ph2.
The [upstream advisories](https://github.com/darylldoyle/svg-sanitizer/security/advisories)
describe the original attack classes; Bricks' fixtures exercise its integration.

Upstream 1.1.1 rejects malformed/non-SVG documents and unsafe encoded DTDs, and
removes unsafe URL animations and excessive `<use>` expansion. Large legitimate
reference graphs can therefore lose references that were previously retained.
These are intentional security restrictions; do not raise limits automatically.

This change applies to **new uploads and imports**. It neither rewrites existing
Media Library files nor adds rendering-time sanitization to existing SVGs.
