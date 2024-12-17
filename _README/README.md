#APC D9/D10 version

## Hosting
hosted at greengeeks.com


## Sites
- www.austinprogressivecalendar.com - d10 live site - ~/www/d9 (/home/austinpr/www/d9)
- d9.austinprogressivecalendar.com - redirects to d10 live site

- d7.austinprogressivecalendar.com - old d7 live site - ~/www/live
- dev.austinprogressivecalendar.com - d7 dev site - ~/www/dev

From Domains in cPanel
- austinprogressivecalendar.com - /public_html
- d7.austinprogressivecalendar.com - /public_html/live/docroot
- d9.austinprogressivecalendar.com - /public_html/d9/web
- dev.austinprogressivecalendar.com - /public_html/dev/docroot


# .htaccess
From ~/www:

```
#RewriteEngine on
#RewriteRule (.*) live/docroot/$1 [L]

#RewriteBase /web

RewriteEngine on
RewriteCond %{HTTP_HOST} ^austinprogressivecalendar.com$
RewriteCond %{REQUEST_URI} !^.*www.*$
RewriteRule ^(.*)$ http://www.austinprogressivecalendar.com [R=301]

RewriteCond %{HTTP_HOST} ^www\.austinprogressivecalendar\.com$ [NC]
RewriteRule ^$ d9/web/index.php [L]
RewriteCond %{HTTP_HOST} ^www\.austinprogressivecalendar\.com$ [NC]
RewriteCond %{DOCUMENT_ROOT}/d9/web%{REQUEST_URI} -f
RewriteRule .* d9/web/$0 [L]
RewriteCond %{HTTP_HOST} ^www\.austinprogressivecalendar\.com$ [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule .* d9/web/index.php?q=$0 [QSA]
```



## Gitflow
- work in develop branch
- merge into main
- deploy main
-



## DDEV

Create a .ddev/config.local.yaml and include the following:

```
router_http_port: "80"
router_https_port: "443"
timezone: America/Chicago
```



