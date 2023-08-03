# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)

## [Unreleased]

## [3.1.0] - 2023-08-03

### Added

- Added support for `authorization code flow`.

### Removed

- Removed support for `openid connect implicit`.

## [3.0.3] - 2023-03-01

- Fixed return annotation.

## [3.0.2] - 2022-09-14
### Fixed
- State passed instead of nonce when validating id token

## [3.0.1] - 2022-09-13
### Fixed
- Auto wiring when `itkdev_openid_connect.user_provider` was configured

## [3.0.0] - 2022-09-13
### Added
- Support for multiple user providers
- Symfony 6.x support
- Rector tooling
- php-cs-fixer tooling

### Removed
- PHP 7.4 and 8.0 support
- phpcodesniffer

## [2.0.0] - 2021-12-08
### Added
- Migrated to Symfony's new (5.1+) security system
### Changed
- Require Symfony 5.4
- Moved `leeway` config to provider config
- ITK OpenID Connect: Upgraded from
  `itk-dev/openid-connect` 2.1.0 to 3.0.0
### Removed
- Remove support for PHP 7.3

## [1.1.0] - 2021-12-08
### Added
- Support for multiple open id connect configuration providers

## [1.0.1] - 2021-09-20
### Fixed
- Updated README
- Avoided duplicate cache configuration

## [1.0.0] - 2021-09-16
### Added
- README
- LICENSE
- OpenID Connect Bundle: Added bundle files, a login controller and an abstract authenticator.
- This CHANGELOG file to hopefully serve as an evolving example of a
  standardized open source project CHANGELOG.
- PHP-CS-Fixer
- Markdownlint
- Test Suite
- Psalm setup for static analysis
- Code formatting
- ITK OpenID Connect: Upgraded from
  `itk-dev/openid-connect` 1.0.0 to 2.1.0
- OpenId Connect Bundle: Added CLI login feature.

[unreleased]: https://github.com/itk-dev/openid-connect-bundle/compare/3.1.0...HEAD
[3.1.0]: https://github.com/itk-dev/openid-connect-bundle/compare/3.0.3...3.1.0
[3.0.3]: https://github.com/itk-dev/openid-connect-bundle/compare/3.0.2...3.0.3
[3.0.2]: https://github.com/itk-dev/openid-connect-bundle/compare/3.0.1...3.0.2
[3.0.1]: https://github.com/itk-dev/openid-connect-bundle/compare/3.0.0...3.0.1
[3.0.0]: https://github.com/itk-dev/openid-connect-bundle/compare/2.0.0...3.0.0
[2.0.0]: https://github.com/itk-dev/openid-connect-bundle/compare/1.1.0...2.0.0
[1.1.0]: https://github.com/itk-dev/openid-connect-bundle/compare/1.0.1...1.1.0
[1.0.1]: https://github.com/itk-dev/openid-connect-bundle/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/itk-dev/openid-connect-bundle/releases/tag/1.0.0
