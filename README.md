# TileCache

A simple tilecache written in Apache and PHP.

Apache directly serves locally cached tiles.
PHP requests new tiles from providers and caches them locally.


## Installation Note

```bash
# Allow the installation folder to be owned by the website user so that new tile layers can be added:
chown www-data .
# Group writable so that updating will work
chmod g+w .
```
