#D9Starter - Drupal 9 starter project


## DDEV

Create a .ddev/config.local.yaml and include the following:

```
router_http_port: "80"
router_https_port: "443"
timezone: America/Chicago
```



## Drush usage

If you want to use global drush command from the host.  e.g. `drush en token` like you used to in, you will notice errors and the command will fail.

To allow local drush on host:


`ddev get rfay/ddev-drushonhost`

In sites/default/settings.local.php with the following code:
```
<?php
putenv("IS_DDEV_PROJECT=true");
```

https://selwynpolit.github.io/d9book/drush#global-drush

See https://github.com/rfay/ddev-drushonhost for docs

Discussion: https://github.com/ddev/ddev/pull/5328


