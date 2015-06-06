# How to update private packages as soon as I push to my remote repository?

There are several ways to configure git post-receive hooks (or equivalent).

## GitHub

- Go to your repository's settings panel, then into "Webhooks & Services".
- Click "Add Service" and search for the "Packagist" one. You can enter anything in the User and Token fields, then enter `http://toran.example.com/` as the Domain. If you secured Toran with basic HTTP auth you can add this using `username:password@` before the hostname.
- Alternatively, if the above does not work for you, you can also use "Add Webhook" instead. In this case the Payload URL should be set to `http://toran.example.com/update-package`, the Content-type to `application/json` and leave the Secret empty. Again basic HTTP auth credentials can be provided in the URL.

## BitBucket

- Go to your repository's settings panel, then into "Hooks".
- Add a "POST" hook and then enter `http://toran.example.com/update-package` as the URL. If you secured Toran with basic HTTP auth you can add this using `username:password@` before the hostname.

## GitLab

- Go to your repository's settings panel, then into "Web Hooks".
- Add a hook for "Push events" using `http://toran.example.com/update-package` as the URL. If you secured Toran with basic HTTP auth you can add this using `username:password@` before the hostname.

## Custom

- You can manually send notifications to Toran by doing a POST request to `http://toran.example.com/update-package` with the following JSON request body:

    ```json
    {
        "repository": {
            "url": "...the VCS url Toran should update..."
        }
    }
    ```

    It also requires a `Content-Type` header set to `application/json`. Here is a sample curl call to produce such a request:

    ```
    curl -XPOST -H 'Content-Type:application/json' http://toran.example.com/update-package --data '{"repository":{"url":"https://github.com/example/repository"}}'
    ```