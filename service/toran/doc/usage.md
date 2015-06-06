# Usage

## Enabling Toran in Composer

To use your Toran installation in Composer, there are two package repositories you can use.

The first is your repository of private packages, if you have any.

```json
{
    "repositories": [
        {"type": "composer", "url": "http://toran.example.com/repo/private/"}
    ]
}
```

The second is the packagist.org proxy, if you use it you should disable packagist to avoid loading every package twice.

```json
{
    "repositories": [
        {"type": "composer", "url": "http://toran.example.com/repo/packagist/"},
        {"packagist": false}
    ]
}
```

Typically you will want to add both, and you should place your private repository on top so it has higher priority, e.g.:

```json
{
    "repositories": [
        {"type": "composer", "url": "http://toran.example.com/repo/private/"},
        {"type": "composer", "url": "http://toran.example.com/repo/packagist/"},
        {"packagist": false}
    ]
}
```

<a id="packagist"></a>
## Packagist Proxy

Making use of the Packagist Proxy functionality means that all package metadata that usually comes from packagist.org will instead flow through your Toran instance. It makes Composer installs more reliable and faster. 

If it is enabled and you use it in Composer, the packages you use from Packagist will automatically be added to this list of synced packages.

Package data (zip files and git clones) usually coming from GitHub or other services will also flow through Toran and be cached there so that if any third party services is down you can still run Composer installs and updates. 

Toran acts as a Composer proxy which also means that if your Toran is down Composer installs from a composer.lock file will fallback to fetching package data from their original (GitHub, ..) URLs, making it all very resilient for deployments and CI builds.

<a id="private"></a>
## Private Repositories

Not all packages should be open-sourced and publicly accessible on packagist.org. Private packages can be added in the "Private Repositories" tab so that their information gets pre-fetched and cached in Toran. 

They will then become available as zip downloads or git clones to Composer if you add this Toran instance to your composer.json. It speeds up Composer updates quite a bit compared to having the custom repositories defined in your composer.json directly, and gives you a convenient overview of all your private packages.

<a id="updates"></a>
## Updating Toran

When a new version of Toran Proxy is available, you can use the update command to download and install it. To do so open an ssh session and navigate to Toran's base directory. You can then run `php app/console toran:update` to start the process. If all happens successfully you should now be running the latest version.

In case the above did not work (if you host on Windows or have extremely restrictive permissions it might fail). You can also do a manual update by [downloading a new release](https://toranproxy.com/download) and extracting it over the existing directory. Then remove `app/cache/prod` and you should be good to go.
