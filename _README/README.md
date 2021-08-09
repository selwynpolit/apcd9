#D9Starter - Drupal 9 starter project


## DDEV

Create a .ddev/config.local.yaml and include the following:

```
router_http_port: "80"
router_https_port: "443"
timezone: America/Chicago
# and for nfs
nfs_mount_enabled: true
```



## Drush usage

Note ddev v1.15 has some new idiosyncracies to be aware of

If you want to use global drush command from the host.  e.g. `drush en token` like you used to in Drupal 8, you will notice errors and the command will fail.

The fix is to add a sites/default/settings.local.php with the following code:
```
<?php
putenv("IS_DDEV_PROJECT=true");
```


