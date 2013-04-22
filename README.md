A simple tilecache written in Apache and PHP.

Apache directly serves locally cached tiles.
PHP requests new tiles from providers and caches them locally.

Allow the installation folder to be owned by the website user so that new tile layers can be added:

```bash
chown www-data .
```
