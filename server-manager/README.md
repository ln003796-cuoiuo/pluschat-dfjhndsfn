# Pluschat Server Manager (PHP MCP bridge)

This is the server-side bridge for a private ChatGPT plugin. It is designed for PHP 8.1+ shared hosting and confines every file operation to one configured directory.

## Target supplied for this installation

- Site: https://xn--80avljg2a1c.xn--p1ai/
- Allowed filesystem root: `/www/xn--80avljg2a1c.xn--p1ai`
- FTP host/port: `195.3.246.75:21` (credentials are not stored in this repository)

## Install

1. Upload the complete `server-manager` folder using your hosting file manager or FTP into the allowed root, so the endpoint becomes:
   `/www/xn--80avljg2a1c.xn--p1ai/pluschat-manager/mcp.php`
2. Copy `config.example.php` to `config.php`.
3. In `config.php`, confirm the root path is exactly the allowed directory and replace the token with a unique random secret of at least 40 characters. Do not send this token in chat or commit it to Git.
4. Keep `.htaccess` in this folder. Confirm the hosting uses Apache/LiteSpeed and honors these rules; if not, deny HTTP access to config and backups in ISPmanager.
5. Enable HTTPS for the domain. The MCP endpoint must be:
   `https://xn--80avljg2a1c.xn--p1ai/pluschat-manager/mcp.php`
6. Test the endpoint with a JSON-RPC initialize request using an Authorization: Bearer header. Do not test by opening it in a browser (GET is intentionally rejected).

## Security boundaries

- Bearer token authentication; HTTPS is mandatory for production.
- All paths are relative to the configured root. Absolute paths, traversal, symlinks, and access to the bridge folder are rejected.
- File reads are limited to 1 MiB; writes to 2 MiB.
- Existing files are backed up privately before overwrite or deletion.
- Only regular files can be deleted. Directory deletion, recursive replacement, shell commands, and access outside the configured root are not exposed.
- Backups are stored in this folder's `backups/` directory and must not be web-accessible.
- Rotate the token immediately if it is exposed. Use a dedicated FTP account jailed to this site directory.

## Important

This source commit does not deploy files to the hosting account and does not create a connected ChatGPT plugin yet. The bridge must first be uploaded and its HTTPS endpoint verified. Only then can the plugin manifest be created with the real endpoint and authentication configuration.
