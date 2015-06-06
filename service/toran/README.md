# Initial install

1. Extract the tgz archive in some directory

2. Copy `app/config/parameters.yml.dist` to `app/config/parameters.yml`

3. Tweak the values in `app/config/parameters.yml` to match the domain/path where you will host toran

4. Make app/toran, app/cache, app/logs, web/repo and app/bootstrap.php.cache writable to (or owned by) the web user

5. Ideally you should set up a vhost like toran.example.org pointing to the web/ directory. 

Apache should configure itself correctly with the .htaccess, for nginx you should put something like this for the rewrite rules:

        location / {
            try_files $uri /app.php$is_args$args;
        }

        location ~ ^/app\.php(/|$) {
            fastcgi_pass   127.0.0.1:9000;
            fastcgi_split_path_info ^(.+\.php)(/.*)$;
            include fastcgi_params;
            fastcgi_param  SCRIPT_FILENAME    $document_root$fastcgi_script_name;
            fastcgi_param  HTTPS              off;
        }

You should also think about securing the vhost with htpasswd/auth_basic rules.

6. Run http://toran.example.org or if you have no vhost make sure you access the `web/app.php` file in your browser, the remaining steps of the installation will be detailed there.

# Updates

1. Extract the new tgz archive over the existing one

2. Clear the `app/cache/prod` directory

3. That should be all!
