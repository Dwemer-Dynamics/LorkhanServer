# Bundled YAML dependency

Symfony Yaml v7.4.18 and Deprecation Contracts v3.7.1 are distributed unmodified
with their upstream licenses. Versions resolved by Composer with no security
advisories at the time of this change. Native ext-ctype is required instead of
the polyfill. The custom server autoloader maps the Symfony YAML namespace and
loads the upstream deprecation function; the optional Console command is unused.

Source: https://github.com/symfony/yaml/tree/v7.4.18
https://github.com/symfony/deprecation-contracts/tree/v3.7.1

When updating, replace the complete YAML package, retain the licenses, confirm
PHP 8.2 compatibility and run the existing malformed/deep/alias/tag/body tests.
Do not enable YAML object deserialization, constants, custom tags or aliases.
