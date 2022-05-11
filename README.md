# Approov QuickStart - PHP Token Check

[Approov](https://approov.io) is an API security solution used to verify that requests received by your backend services originate from trusted versions of your mobile apps.

This repo implements the Approov server-side request verification code in PHP (framework agnostic), which performs the verification check before allowing valid traffic to be processed by the API endpoint.


## Approov Integration Quickstart

The quickstart was tested with the following Operating Systems:

* Ubuntu 20.04
* MacOS Big Sur
* Windows 10 WSL2 - Ubuntu 20.04

First, setup the [Appoov CLI](https://approov.io/docs/latest/approov-installation/index.html#initializing-the-approov-cli).

Now, register the API domain for which Approov will issues tokens:

```bash
approov api -add api.example.com
```

Next, enable your Approov `admin` role with:

```bash
eval `approov role admin`
```

Now, get your Approov Secret with the [Appoov CLI](https://approov.io/docs/latest/approov-installation/index.html#initializing-the-approov-cli):

```bash
approov secret -get base64
```

> **@IMPORTANT:**
> Don't set an Approov key id for the secret, because the JWT library doesn't support to pass the symmetric key for the Approov secret in a JWKs.

Next, add the [Approov secret](https://approov.io/docs/latest/approov-usage-documentation/#account-secret-key-export) to your project `.env` file:

```env
APPROOV_BASE64_SECRET=approov_base64_secret_here
```

Now, add to your project the [firebase/php-jwt](https://github.com/firebase/php-jwt) package to check the JWT token:

```bash
composer require firebase/php-jwt
```

Next, add this code to your project:

```php
$env = Dotenv\Dotenv::createArrayBacked(__DIR__)->load();

if (empty($env['APPROOV_BASE64_SECRET'])) {
    throw new Exception("Missing in the .env file the variable: APPROOV_BASE64_SECRET");
}

define('APPROOV_BASE64_SECRET', base64_decode($env['APPROOV_BASE64_SECRET'], true));

function verifyApproovToken(Array $headers): ?stdClass {
    try {
        if (empty($headers['Approov-Token'])) {
            // You may want to add some logging here
            return null;
        }

        $approov_token = $headers['Approov-Token'];

        // The Approov secret cannot be given as part of a JWKS key set,
        // therefore you cannot use the Approov CLI to set a key id for it.
        //
        // If you set the key id then the token check will fail due to the
        // presence of a `kid` key in the header of the Approov token, that
        // will not be found in the `$approov_secret` variable, because this
        // variable contains the secret as a binary string, not as a JWKs
        // key set.
        return \Firebase\JWT\JWT::decode($approov_token, constant('APPROOV_BASE64_SECRET'), ['HS256']);

    } catch(\UnexpectedValueException $exception) {
        // You may want to add some logging here
        return null;
    } catch(\InvalidArgumentException $exception) {
        // You may want to add some logging here
        return null;
    } catch(\DomainException $exception) {
        // You may want to add some logging here
        return null;
    }

    // You may want to add some logging here
    return null;
}
```

Now you just need to invoke `verifyApproovToken()` function for the endpoints you want to protected:

```php
$headers = getallheaders();
$approov_token_claims = verifyApproovToken($headers);

if (!$approov_token_claims) {
    sendResponse(401, []);
    exit;
}
```

> **NOTE:** When the Approov token validation fails we return a `401` with an empty body, because we don't want to give clues to an attacker about the reason the request failed, and you can go even further by returning a `400`.

Not enough details in the bare bones quickstart? No worries, check the [detailed quickstarts](QUICKSTARTS.md) that contain a more comprehensive set of instructions, including how to test the Approov integration.


## More Information

* [Approov Overview](OVERVIEW.md)
* [Detailed Quickstarts](QUICKSTARTS.md)
* [Examples](EXAMPLES.md)
* [Testing](TESTING.md)


## Issues

If you find any issue while following our instructions then just report it [here](https://github.com/approov/quickstart-php-token-check/issues), with the steps to reproduce it, and we will sort it out and/or guide you to the correct path.


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
