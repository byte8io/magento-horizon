# Changelog

## [1.3.2](https://github.com/byte8io/magento-horizon/compare/v1.3.1...v1.3.2) (2026-10-26)


### Bug Fixes

* API key field is masked and never self-generated ([6fb738a](https://github.com/byte8io/magento-horizon/commit/6fb738ae3d534e402da067e840d725fa1020ec09))
* default Gateway URL to https://horizon.byte8.io ([65aab74](https://github.com/byte8io/magento-horizon/commit/65aab7422c9b0b97fb1f4e8a878b2c6cdd4d3a25))
* reindex no longer dies on catalogues without the sample-data attributes ([263453d](https://github.com/byte8io/magento-horizon/commit/263453dcd9dd742dbda57d5c836b896eae982ed9))

## [1.3.1](https://github.com/byte8io/magento-horizon/compare/v1.3.0...v1.3.1) (2026-10-24)


### Bug Fixes

* report the installed package version from composer.json in /health ([4e3ec6f](https://github.com/byte8io/magento-horizon/commit/4e3ec6fed25ab14ed376b66b5aee30e35e126c46))


### Documentation

* fix documentation links to include the /docs/ route segment ([085c755](https://github.com/byte8io/magento-horizon/commit/085c755bc103e4871f27797e1c72b8e8b317c5cb))

## [1.3.0](https://github.com/byte8io/magento-horizon/compare/v1.2.1...v1.3.0) (2026-10-20)


### Features

* absolute storefront url on product search and detail responses ([4234b15](https://github.com/byte8io/magento-horizon/commit/4234b150198015c66d8202fe190d168b99469c0b))
* delivery estimates — shipments, tracking and arrival dates on orders ([6436a1a](https://github.com/byte8io/magento-horizon/commit/6436a1a31612ff36faaa846c237b69c6bd97cd4f))
* expose tax settings in store-info ([2be0680](https://github.com/byte8io/magento-horizon/commit/2be06806ac588147e8476a4513c4150f44d22fe5))
* invoices, credit memos and cancellation facts on order detail ([0725a1f](https://github.com/byte8io/magento-horizon/commit/0725a1f5791c384e10a01d050a4c01e3791e5cb1))
* payment summary on order detail ([4b24ec1](https://github.com/byte8io/magento-horizon/commit/4b24ec16971ee5bd983e1194d64b65c02362fafb))
* product reviews for AI agents and the assistant ([c77d1c1](https://github.com/byte8io/magento-horizon/commit/c77d1c118669083795f946179ac37ee079611d8b))
* register the flat AI index as a native Magento indexer ([a940a01](https://github.com/byte8io/magento-horizon/commit/a940a01344d02826c00e3645fa5859e3a7470c68))
* shipping estimate + store-info endpoints for the AI assistant ([500c811](https://github.com/byte8io/magento-horizon/commit/500c81133e14fbb97cdabaf9c5f339a2a89c7bf6))
* shipping estimate reports why free shipping applies ([368d64d](https://github.com/byte8io/magento-horizon/commit/368d64d4556c3b115880241a70e1c5ff075878a2))
* shopper-friendly search and composite product prices ([5815cd7](https://github.com/byte8io/magento-horizon/commit/5815cd7ff8525fbb4d53391e5e04863f23a3aab8))
* sold_qty on the product index and ranked search ([584ca21](https://github.com/byte8io/magento-horizon/commit/584ca2182ee3a028016af515e5e4b052697de98e))

## [1.2.1](https://github.com/byte8io/magento-horizon/compare/v1.2.0...v1.2.1) (2026-09-26)


### Documentation

* public README — product links, install, config, architecture ([8930ade](https://github.com/byte8io/magento-horizon/commit/8930adee1833e56ef96860b5f4c729f8ba8d6868))

## [1.2.0](https://github.com/byte8io/magento-horizon/compare/v1.1.0...v1.2.0) (2026-09-26)


### Features

* AI Shopping Assistant storefront widget integration ([dd2f3f0](https://github.com/byte8io/magento-horizon/commit/dd2f3f0921e102e47e6553bc6649be3ae03a386a))


### Bug Fixes

* relax byte8/module-core constraint to allow 3.x ([605131f](https://github.com/byte8io/magento-horizon/commit/605131f820963949d0a96ef636a2ebfbe0511cd1))

## [1.1.0](https://github.com/byte8io/magento-horizon/compare/v1.0.0...v1.1.0) (2026-06-15)


### Features

* outbound catalog-invalidation webhooks + category/stock observers + MSI plugin ([a73cfb0](https://github.com/byte8io/magento-horizon/commit/a73cfb0fa588ded723c47a04226062891f54f6b7))


### Bug Fixes

* flatten ACL to avoid duplicate Magento_Config::config ([16e6059](https://github.com/byte8io/magento-horizon/commit/16e6059c8af2091dc957b48efd21e12506b92ce5))
