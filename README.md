# OpenId Connect Bundle

Symfony bundle for OpenID Connect

## Installation

To install run

```shell
composer require itk-dev/openid-connect-bundle
```

If you wish to run the coding standard tests for Markdown files

```sh
yarn install
```


## Usage

### Example configuration

## Changes for Symfony 6.0

In Symfony 6.0 a new security system will be 
[introduced](https://symfony.com/doc/current/security/experimental_authenticators.html). 
This system is said to be almost fully backwards compatible, but changes may be needed. 
If so a new version of this bundle might be necessary. 

## Coding standard tests

The following command let you test that the code follows
the coding standard we decided to adhere to in this project.

* PHP files (PHP-CS-Fixer)

    ```sh
    ./vendor/bin/php-cs-fixer fix src --dry-run
    ```

* Markdown files (markdownlint standard rules)

    ```sh
    yarn coding-standards-check
    ```

## Versioning

We use [SemVer](http://semver.org/) for versioning.
For the versions available, see the
[tags on this repository](https://github.com/itk-dev/openid-connect-bundle/tags).

## License

This project is licensed under the MIT License - see the
[LICENSE.md](LICENSE.md) file for details