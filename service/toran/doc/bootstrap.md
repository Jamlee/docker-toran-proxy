# Bootstrapping

To get Toran to be fully running you need you need to run the background update task once manually and then set-up a cron job.

Note that all the instructions below should be executed as the php web user (www-data or equivalent) to avoid permission issues later on.

The background tasks are centralized and automatically managed by the `bin/cron` php script. Run it once in verbose mode as `php bin/cron -v` to make sure everything works fine and so it can ask you for credentials should they be needed.

Once the first run is complete, you should add a cron job running every minute that executes `cd /path/to/toran && php bin/cron` - this will take care of keeping your packages up to date at all times.

You are now done with setup and can proceed to the [usage instructions](usage.md).
