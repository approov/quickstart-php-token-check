# Unprotected Server Example

The unprotected example is the base reference to build the [Approov protected servers](/src/approov-protected-server/). This a very basic Hello World server.


## TOC - Table of Contents

* [Why?](#why)
* [How it Works?](#how-it-works)
* [Requirements](#requirements)
* [Try It](#try-it)


## Why?

To be the starting building block for the [Approov protected servers](/src/approov-protected-server/), that will show you how to lock down your API server to your mobile app. Please read the brief summary in the [Approov Overview](/OVERVIEW.md#why) at the root of this repo or visit our [website](https://approov.io/product) for more details.

[TOC](#toc---table-of-contents)


## How it works?

The PHP server is very simple and is defined in the file [src/unprotected-server/hello-server-unprotected.php](/src/unprotected-server/hello-server-unprotected.php).

The server only replies to the endpoint `/` with the message:

```json
{"message": "Hello, World!"}
```

[TOC](#toc---table-of-contents)


## Requirements

To run this example you will need to have PHP installed. If you don't have then please follow the official installation instructions from [here](https://www.php.net/manual/en/install.php) to download and install it.

[TOC](#toc---table-of-contents)


## Try It

Run this example from the `src/unprotected-server` folder with:

```bash
php -S localhost:8002 hello-server-unprotected.php
```

Now, you can test that it works with:

```bash
curl -iX GET 'http://localhost:8002'
```

The response will be:

```text
HTTP/1.1 200 OK
Host: localhost:8002
Date: Tue, 22 Mar 2022 15:10:44 GMT
Connection: close
X-Powered-By: PHP/8.1.4
Content-Type: application/json
Content-Length: 27

{"message":"Hello, World!"}
```

[TOC](#toc---table-of-contents)


## Issues

If you find any issue while following our instructions then just report it [here](https://github.com/approov/quickstart-php-token-check/issues), with the steps to reproduce it, and we will sort it out and/or guide you to the correct path.

[TOC](#toc---table-of-contents)


## Useful Links

If you wish to explore the Approov solution in more depth, then why not try one of the following links as a jumping off point:

* [Approov Free Trial](https://approov.io/signup)(no credit card needed)
* [Approov Get Started](https://approov.io/product/demo)
* [Approov QuickStarts](https://approov.io/docs/latest/approov-integration-examples/)
* [Approov Docs](https://approov.io/docs)
* [Approov Blog](https://approov.io/blog/)
* [Approov Resources](https://approov.io/resource/)
* [Approov Customer Stories](https://approov.io/customer)
* [Approov Support](https://approov.zendesk.com/hc/en-gb/requests/new)
* [About Us](https://approov.io/company)
* [Contact Us](https://approov.io/contact)

[TOC](#toc---table-of-contents)
