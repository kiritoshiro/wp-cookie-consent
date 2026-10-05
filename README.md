# WP Cookie Consent

A WordPress GDPR cookie consent plugin with automatic cookie scanning and Lithuanian, English, and Russian interfaces.

## Requirements

- WordPress 6.4 or newer
- PHP 8.4 or newer

## Release updates

The plugin checks the latest published, non-prerelease release in this public repository. No token is needed.

A token is optional. A fine-grained GitHub personal access token with **Contents: Read** access to this repository raises the GitHub API rate limit, and is required again only if the repository becomes private. Add it to wp-config.php, before the “That's all, stop editing” line:

    define( 'WP_COOKIE_CONSENT_GITHUB_TOKEN', 'github_pat_...' );

Keep the token out of Git and backups shared outside the WordPress server. It is sent only to api.github.com, never to the download redirect. The updater stores release metadata in a short-lived WordPress transient; it does not save the token in the database.

## Publishing a release

1. Update the plugin header version and the VERSION constant in adventistai-cookie-consent.php.
2. Update the stable tag and changelog in readme.txt.
3. Create and publish a GitHub release whose tag matches the version, such as v1.0.11.
4. The Build plugin release workflow attaches wp-cookie-consent.zip. WordPress offers the release after this asset is attached.

The workflow keeps the existing adventistai-cookie-consent installation folder and main PHP filename so current installations can upgrade in place. The GitHub repository and displayed plugin name use wp-cookie-consent / WP Cookie Consent.

## License

GPL-2.0-or-later.
